<?php
require_once("const.php");

function is_valid_mailaddress($address) {
    if (preg_match("/^([a-zA-Z0-9])+([a-zA-Z0-9\._-])*@([a-zA-Z0-9_-])+([a-zA-Z0-9\._-]+)+$/", $address)) {
        return true;
    }
    return false;
}

function init_history() {
    $raw = sprintf("%s,%s\n", "日時", "送信件数", "送信元");
    return file_put_contents(HISTORY_CSV, $raw);
}

function append_history($send_count, $send_from) {
    if (!file_exists(HISTORY_CSV)) {
        init_history();
    }
    $time_str = date("Y/m/d H:i:s");
    $raw = sprintf("%s,%s,%s\n", $time_str, $send_count, $send_from);
    return file_put_contents(HISTORY_CSV, $raw, FILE_APPEND);
}

function render($str = "", $display = false) {
    static $html = "";
    if (!empty($str)) {
        $html .= "<p>{$str}</p>";
    }

    if ($display) {
        $html = <<< EOS
<html>
<head>
<meta charset="utf-8"/>
</head>
<body>
<p>{$html}</p>
<p><a href="./">戻る</a></p>
</body>
</html>
EOS;
        echo $html;
        $html = "";
    }
}

function render_exit($str = "") {
    render($str, true);
    exit;
}

function replace_name($str, $name) {
    $str = str_replace('{name}', $name, $str);
    return $str;
}

$from = $_POST["from"];
$title_tpl = $_POST["title"];
$body_tpl = $_POST["body"];
$mail_headers = "From: {$from}";
$mail_opt = "-f{$from}";

$mail = array();
$file = fopen($_FILES["csv"]["tmp_name"], "r");
if (!$file) {
    render_exit("csvファイルの読み込みに失敗しました");
}
while ($line = fgets($file)) {
    list($address, $name) = explode(",", $line);
    $address = trim($address);
    $name = trim($name);
    $mail[] = array($address, $name);
}
fclose($file);
if (!$mail) {
    render_exit("メールアドレスと名前のペアが1件もありません");
}

$mail_address_fail = array();
$mail_address_success = array();
foreach ($mail as $mail_pair) {
    $address = $mail_pair[0];
    $name = $mail_pair[1];
    $title = replace_name($title_tpl, $name);
    $body = replace_name($body_tpl, $name);
    if (!is_valid_mailaddress($address)) {
        $mail_address_fail[] = $address;
        continue;
    }
    if (!mail($address, $title, $body, $mail_headers, $mail_opt)) {
        $mail_address_fail[] = $address;
        continue;
    }
    $mail_address_success[] = $address;
}

append_history(count($mail_address_success), $from);
render(count($mail_address_success). "件にメールを送信しました");
if ($mail_address_fail) {
    render("以下の宛先への送信に失敗しました");
    $str = "<pre>";
    foreach ($mail_address_fail as $address) {
        $str .= "{$address}\n";
    }
    $str .= "</pre>";
    render($str);
}
render_exit();
