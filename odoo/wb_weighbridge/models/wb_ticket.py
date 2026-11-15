import json
import logging
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime

try:
    from zoneinfo import ZoneInfo
except ImportError:  # pragma: no cover
    ZoneInfo = None

from odoo import api, fields, models, _
from odoo.exceptions import UserError

_logger = logging.getLogger(__name__)


class WbTicket(models.Model):
    _name = 'wb.ticket'
    _description = 'Weighbridge ticket'
    _inherit = ['mail.thread']
    _order = 'first_weight_at desc, id desc'
    _rec_name = 'ticket_no'

    ticket_no = fields.Char(required=True, copy=False, index=True, tracking=True)
    state = fields.Selection([('OPEN', 'Open'), ('CLOSED', 'Closed'), ('CANCELLED', 'Cancelled')],
                             default='CLOSED', required=True, tracking=True)
    direction = fields.Selection([('INWARD', 'Inward (receipt)'), ('OUTWARD', 'Outward (dispatch)')],
                                 default='INWARD', required=True)
    vehicle_no = fields.Char(index=True)
    driver = fields.Char()
    challan_no = fields.Char('Challan / LR no.')
    party_name = fields.Char('Party (as typed)')
    material_name = fields.Char('Material (as typed)')
    partner_id = fields.Many2one('res.partner', 'Partner', index=True,
                                 help='Found by exact name; set it by hand if the weighbridge name differs.')
    product_id = fields.Many2one('product.product', 'Product', index=True)
    purchase_id = fields.Many2one('purchase.order', 'Purchase order', index=True)
    po_ref = fields.Char('PO reference', help='Purchase order number if the challan / remarks carry one.')
    gross_kg = fields.Float(digits=(16, 3))
    tare_kg = fields.Float(digits=(16, 3))
    net_kg = fields.Float(digits=(16, 3), tracking=True)
    net_ton = fields.Float('Net (tonnes)', compute='_compute_net_ton', store=True, digits=(16, 3))
    first_weight_at = fields.Datetime()
    second_weight_at = fields.Datetime()
    operator = fields.Char()
    scale_name = fields.Char('Scale')
    plate_in = fields.Char('Plate in (ANPR)')
    plate_out = fields.Char('Plate out (ANPR)')
    plate_flag = fields.Char('Plate check')
    remarks = fields.Text()
    local_id = fields.Integer('Weighbridge id', index=True)

    _sql_constraints = [('ticket_no_uniq', 'unique(ticket_no)', 'This weighbridge ticket already exists.')]

    @api.depends('net_kg')
    def _compute_net_ton(self):
        for r in self:
            r.net_ton = (r.net_kg or 0.0) / 1000.0

    # ------------------------------------------------------------------ link to Odoo records
    @api.model
    def _resolve_links(self, vals):
        """Fill partner / product / purchase order from the typed names when they match exactly one record."""
        vals = dict(vals)
        if vals.get('party_name') and not vals.get('partner_id'):
            p = self.env['res.partner'].search([('name', '=ilike', vals['party_name'])], limit=2)
            if len(p) == 1:
                vals['partner_id'] = p.id
        if vals.get('material_name') and not vals.get('product_id'):
            p = self.env['product.product'].search([('name', '=ilike', vals['material_name'])], limit=2)
            if len(p) == 1:
                vals['product_id'] = p.id
        po = vals.get('po_ref') or vals.get('challan_no')
        if po and not vals.get('purchase_id'):
            o = self.env['purchase.order'].search([('name', '=', po)], limit=2)
            if len(o) == 1:
                vals['purchase_id'] = o.id
        return vals

    @api.model_create_multi
    def create(self, vals_list):
        return super().create([self._resolve_links(v) for v in vals_list])

    def write(self, vals):
        res = super().write(vals)
        if any(k in vals for k in ('party_name', 'material_name', 'po_ref', 'challan_no')):
            for r in self:   # only fill links that are still empty: a manual choice is never overwritten
                extra = self._resolve_links({
                    'party_name': r.party_name, 'material_name': r.material_name, 'po_ref': r.po_ref, 'challan_no': r.challan_no,
                    'partner_id': r.partner_id.id, 'product_id': r.product_id.id, 'purchase_id': r.purchase_id.id})
                upd = {k: extra[k] for k in ('partner_id', 'product_id', 'purchase_id') if extra.get(k) and not r[k]}
                if upd:
                    super(WbTicket, r).write(upd)
        return res

    # ------------------------------------------------------------------ pull from the weighbridge REST API
    @api.model
    def _wb_param(self, key, default=''):
        return self.env['ir.config_parameter'].sudo().get_param('wb.' + key, default)

    @api.model
    def _wb_get(self, path):
        base = (self._wb_param('api_url') or '').rstrip('/')
        token = self._wb_param('api_token')
        if not base or not token:
            raise UserError(_('Set the system parameters wb.api_url and wb.api_token first (Settings > Technical > System Parameters).'))
        req = urllib.request.Request('%s/%s' % (base, path), headers={'Authorization': 'Bearer ' + token, 'Accept': 'application/json'})
        try:
            with urllib.request.urlopen(req, timeout=20) as resp:  # nosec - URL is set by an administrator
                return json.loads(resp.read().decode('utf-8'))
        except urllib.error.HTTPError as e:
            raise UserError(_('Weighbridge API answered HTTP %s.') % e.code)
        except (urllib.error.URLError, ValueError, OSError) as e:
            raise UserError(_('Cannot reach the weighbridge API: %s') % e)

    @api.model
    def _to_utc(self, local):
        """The weighbridge reports local time; Odoo stores UTC."""
        if not local:
            return False
        tzname = self._wb_param('api_tz', 'Asia/Kolkata')
        dt = datetime.strptime(local, '%Y-%m-%d %H:%M:%S')
        if ZoneInfo:
            dt = dt.replace(tzinfo=ZoneInfo(tzname)).astimezone(ZoneInfo('UTC')).replace(tzinfo=None)
        return dt

    @api.model
    def _vals_from_api(self, t):
        return {
            'ticket_no': t['ticket_no'], 'state': t['status'], 'direction': t['direction'], 'vehicle_no': t['vehicle_no'] or False,
            'driver': t['driver'] or False, 'challan_no': t['challan_no'] or False, 'party_name': t['party'] or False,
            'material_name': t['material'] or False, 'gross_kg': t['gross_kg'] or 0.0, 'tare_kg': t['tare_kg'] or 0.0,
            'net_kg': t['net_kg'] or 0.0, 'first_weight_at': self._to_utc(t['first_at']), 'second_weight_at': self._to_utc(t['second_at']),
            'operator': t['operator'] or False, 'scale_name': t['scale'] or False, 'plate_in': t['plate_in'] or False,
            'plate_out': t['plate_out'] or False, 'plate_flag': t['plate_flag'] or False, 'remarks': t['remarks'] or False,
            'local_id': t['id'],
        }

    @api.model
    def _cron_pull_tickets(self, max_pages=20):
        """Fetch closed / cancelled tickets after the last one seen. Safe to run repeatedly (create or update by ticket_no)."""
        since = int(self._wb_param('last_id', '0') or 0)
        total = 0
        for _page in range(max_pages):
            data = self._wb_get('tickets.php?since_id=%d&limit=200' % since)
            for t in data.get('tickets', []):
                vals = self._vals_from_api(t)
                rec = self.search([('ticket_no', '=', vals['ticket_no'])], limit=1)
                if rec:
                    rec.write(vals)
                else:
                    self.create(vals)
                total += 1
            new_since = int(data.get('next_since_id', since))
            if new_since == since or not data.get('tickets'):
                break
            since = new_since
            self.env['ir.config_parameter'].sudo().set_param('wb.last_id', str(since))
        _logger.info('Weighbridge pull: %d ticket(s), cursor %d', total, since)
        return total

    @api.model
    def action_pull_now(self):
        n = self._cron_pull_tickets()
        return {'type': 'ir.actions.client', 'tag': 'display_notification',
                'params': {'title': _('Weighbridge'), 'message': _('%d ticket(s) fetched.') % n, 'sticky': False}}
