<?php
// Stand-in for `alpr`: prints OpenALPR-style JSON (or plain text when argv[2] == 'plain') for the image path in argv[1].
$txt = (string)@file_get_contents($argv[1] ?? '');
$plate = preg_match('/PLATE:([A-Z0-9]+)/', $txt, $m) ? $m[1] : null;
if (($argv[2] ?? '') === 'plain') { echo $plate ? "$plate 0.91\n" : ''; exit; }
echo json_encode(['results' => $plate ? [['plate' => $plate, 'confidence' => 91.5], ['plate' => 'XX00', 'confidence' => 40.0]] : []]);
