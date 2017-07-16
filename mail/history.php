<?php
require_once("const.php");

header("Content-Type: text/csv; charset=UTF-8");
$head = "日時,送信件数,送信元";
print "{$head}\n";

$csv_body = file_get_contents(HISTORY_CSV);
if ($csv_body !== false) {
    print $csv_body;
    exit;
}
