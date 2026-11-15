# Quick setup guide – site without automatic barrier

Process: (1) the vehicle is recorded at the entry gate, (2) it drives to the weighing machine, (3) the software captures the weight and saves it to the database.

## A. Gate (register only, no hardware)
1. Log in as **admin** → **Setup → Gates** → *Add a gate*: name `Entry gate` → open it.
2. **Role:** Entry gate · **Driver:** *Simulator (no hardware)* (this means no barrier is controlled) · **Automatic opening:** off → Save. (Optional: add `Exit gate` the same way with Role *Exit gate*.)
3. Under *Gate entry rules* choose *Warn* (or *Yes – block*) for "Weighing requires a gate entry", and *Warn* for the exit rule.
4. Daily use: **Gate** screen → vehicle number, driver, party, material, challan → leave *Open the gate* unticked → **Register & admit**. The weighbridge ticket takes over party, material, challan and driver from this entry (no retyping). You may also choose "(no gate – register only)".

## B. Scale (weight → database)
1. **Setup → Scales** → *Scale 1*.
2. **Connection type:** *Serial (RS-232 / RS-485)* – port `COM3` (Windows: Device Manager → Ports) or `/dev/ttyUSB0`, baud / data bits / parity / stop bits **exactly as on the indicator** (typically 9600, 8, N, 1); or *TCP/IP* for a serial-to-Ethernet box (IP + port). Protocol *ASCII* unless the indicator speaks Modbus.
3. Press **Test connection**:
   * weight text shown → connection is right (adjust the weight pattern / terminator if the number is not recognised);
   * "no data" → check port, cable (TX/RX/GND; RS-485 A/B), baud/parity, that the indicator sends **continuous output** (or fill *Request command* for poll-type indicators).
4. Watch **Live data**: the value must change with the load and show **stable** when it settles. Save and enable the scale.
5. **The scale reader must be running**: Windows `start.bat` (or install the services with `deploy\windows-install.bat` so it starts with the PC); Linux `bin/scale_supervisor.php` (systemd unit in `deploy/`). If the Weighing screen shows the scale offline, the reader is not running.
6. **Weighing:** *Weighing* screen → vehicle number (or pick from the gate list) → capture when stable → the ticket is saved in the database (`data/weighbridge.sqlite`).

## If it still does not work, send us
Indicator make and model, how it is connected (cable / network box), a screenshot or the text of the **Test connection** result, and a photo of the indicator's communication settings.
