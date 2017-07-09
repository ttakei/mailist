<?php
require_once("const.php");

$file = fopen(HISTORY_CSV, "r");
if (!$file) {
    print "";
    exit;
}

$head = "";
while ($line = fgets($file)) {
    $line = rtrim($line);
    if (empty($head)) {
        $head = $line;
        continue;
    }
    list($date_str, $send_cnt, $send_from) = explode(",", $line);
    $utime = strtotime($date_str);
    if ($utime < strtotime("1 month ago")) {
        continue;
    }
    $history["$utime"] = array($date_str, $send_cnt, $send_from);
}

krsort($history);

print $head."\n";
foreach ($history as $utime => $arr) {
    printf("%s,%s,%s\n", $arr[0], $arr[1], $arr[2]);
}
