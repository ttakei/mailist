<?php
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
<p><a href="/system/">戻る</a></p>
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

$title_tpl = $_POST["title"];
$body_tpl = $_POST["body"];

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
foreach ($mail as $mail_pair) {
    $address = $mail_pair[0];
    $name = $mail_pair[1];
    $title = replace_name($title_tpl, $name);
    $body = replace_name($body_tpl, $name);
    if (!mail($address, $title, $body)) {
        $mail_address_fail[] = $address;
    }
}

render("メールの送信が完了しました");
if ($mail_address_fail) {
    render("送信失敗");
    $str = "<pre>";
    foreach ($mail_address_fail as $address) {
        $str .= "{$address}\n";
    }
    $str .= "</pre>";
    render($str);
}
render_exit();
