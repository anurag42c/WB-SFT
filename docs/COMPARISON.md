# Comparison with public GitHub weighbridge projects

Researched with Firecrawl (public READMEs / file listings, checked 2026-09-29). Only the README-level information
was compared; no third-party code was copied into this project.

| Project | Stack | Serial (RS-232/485) | DB | Camera / ANPR | Status |
|---|---|---|---|---|---|
| [aldysetiaa/Weighing-Scale2](https://github.com/aldysetiaa/Weighing-Scale2) | JS Chrome extension + PHP AJAX API, AdminLTE | RS-232 & RS-485 via Chrome Serial API (README: **no longer supported by new Chrome**) | MySQL | IP-cam snapshot, ANPR, vehicle colour (premium version only) | last real code 2021; free repo is a showcase; premium closed |
| [mulyonost/timbangan](https://github.com/mulyonost/timbangan) | Laravel + `PhpSerial.php` + camera | RS-232 (PHP serial class, direct from web request) | MySQL | webcam capture | 14 commits, last 2023, README is stock Laravel text |
| [Amexatgit/WEIGHBRIDGE-AUTOMATION-SYSTEM](https://github.com/Amexatgit/WEIGHBRIDGE-AUTOMATION-SYSTEM) | C++17, ESP32/RPi, HX711 load cell, OpenCV | none (raw load cell ADC) | MySQL / CSV / Excel-XML | OpenCV plate capture | prototype/GSoC style; not for industrial indicators |
| [mdlayher/php-serial](https://github.com/mdlayher/php-serial) (library) | PHP | RS-232 direct IO | - | - | building block only |

## What this project does differently

| Concern | Typical repo | This project |
|---|---|---|
| Serial access | Read inside the web request or a browser extension (breaks on Chrome updates, blocks PHP workers, one reader per page) | Dedicated daemon owns the port; web reads SQLite. Works with any browser, many operators at once |
| Trust in weight | Browser posts the weight to the server (spoofable) | Server captures the weight itself, only when STABLE and fresh (<3 s) |
| Protocols | One ASCII format hard-coded | Configurable terminator/regex/stable flags/divisor, poll-command indicators, **Modbus RTU (RS-485)**, TCP gateways, simulator |
| Setup | Edit source / config files | Web Setup page with live raw-data view and Test buttons |
| Oracle | none (MySQL only) | Store-and-forward MERGE (idempotent), retry, status per ticket, cancellation propagates |
| Storage | MySQL server required | SQLite (WAL), zero admin, online backups |
| Security | Rarely addressed | bcrypt, CSRF, prepared statements, lockout, CSP headers, encrypted secrets, input allow-listing for shell/socket parameters, roles, audit log |
| Ops | none | systemd/NSSM units, health endpoint, backup script with integrity check, automated tests |

## Ideas taken from the comparison (now implemented)

* **Camera evidence photo** at each weighing (fraud prevention, from Weighing-Scale2 / timbangan) - optional IP-camera snapshot URL.
* **REST API** for ERP integration (Weighing-Scale2 premium feature) - read-only, bearer token, hashed at rest.
* **Single-pass with stored tare** and "smaller weight is the tare" style safeguard (gross must exceed tare).
* **Backups** (Weighing-Scale2 "Backup Query Insert") - consistent SQLite snapshot with integrity check and retention.

## Added later

* **ANPR** – pluggable client (Plate Recognizer, CodeProject.AI, local command such as OpenALPR). Auto-fill of the vehicle number, master lookup, and a second-weighing identity check with warn/block policy and admin override. The OCR itself is delegated to the service you pick; this project does not ship its own model.
* **Several scales on one PC** – per-scale connection/parser/camera, a supervisor that runs one reader per enabled scale, cross-bridge tickets (in on one, out on another), duplicate-port protection.

* **Gate open/close interface + gate entry program** – boom-barrier control (HTTP/TCP/serial/Modbus), gate-pass register, blocklist, exit rules, weighbridge boom automation, event log.

* **Invoice OCR + matching + exceptions + SAP/Oracle transfer** – not found in any of the public projects reviewed (they store weights only); uses a pluggable reader (Claude vision or Tesseract), a human verification step, and the weighbridge ticket as the reference.

## Not implemented (honest gaps)

* Barrier position/safety sensing (the software sends commands; it does not read limit switches or loops), fully unattended admission from a loop-detector trigger.
* Vehicle make/colour recognition (the premium Weighing-Scale2 feature); only the plate is read.
* Accuracy claims for ANPR - depends on camera, lighting and service; tested here only with mock services.
* Legal-for-trade approval, printer-specific slip formats, boom-barrier/traffic-light I/O.
* Not verified against real hardware or a real Oracle instance (see README "Production readiness").
