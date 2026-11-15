{
    'name': 'Weighbridge',
    'version': '17.0.1.0.0',
    'category': 'Inventory/Inventory',
    'summary': 'Weighbridge tickets (gross / tare / net) received from the Weighbridge program',
    'description': """
Receives completed weighbridge tickets from the Weighbridge program (PHP + SQLite) over Odoo's standard
external API, or pulls them from its REST API, and links them to vendors, products and purchase orders.
""",
    'author': 'Weighbridge',
    'license': 'LGPL-3',
    'depends': ['base', 'mail', 'product', 'purchase'],
    'data': [
        'security/wb_security.xml',
        'security/ir.model.access.csv',
        'views/wb_ticket_views.xml',
        'data/ir_cron.xml',
        'data/ir_config_parameter.xml',
    ],
    'application': True,
    'installable': True,
}
