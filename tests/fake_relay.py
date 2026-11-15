"""Fake USB/RS-485 relay board on a pseudo-terminal.  python3 fake_relay.py LOGFILE [echo]
Writes its tty name to LOGFILE.tty, logs received bytes as hex, and (with 'echo') answers Modbus RTU write-coil frames with an echo."""
import os, pty, sys, time, select
log = sys.argv[1]; echo = len(sys.argv) > 2 and sys.argv[2] == 'echo'
m, s = pty.openpty()
open(log + '.tty', 'w').write(os.ttyname(s))
end = time.time() + 60
buf = b''
while time.time() < end:
    r, _, _ = select.select([m], [], [], 0.2)
    if not r:
        continue
    try:
        d = os.read(m, 256)
    except OSError:
        break
    if not d:
        continue
    open(log, 'a').write('%.3f %s\n' % (time.time(), d.hex()))
    if echo:
        buf += d
        while len(buf) >= 8:
            os.write(m, buf[:8]); buf = buf[8:]
