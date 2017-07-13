<?php
require_once("const.php");

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

function is_valid_mailaddress($address) {
    if (preg_match("/^([a-zA-Z0-9])+([a-zA-Z0-9\._-])*@([a-zA-Z0-9_-])+([a-zA-Z0-9\._-]+)+$/", $address)) {
        return true;
    }
    return false;
}

function build_mail_title($title) {
    return "=?iso-2022-jp?B?". base64_encode(mb_convert_encoding($title, "JIS", "UTF-8")). "?=";
}

function build_body_include_attachment($body, $attachment_type, $attachment_name, $attachment_raw, $boundary) {
    $attachment_base64_chunk = chunk_split(base64_encode($attachment_raw));

    $body = <<< EOS
--{$boundary}
Content-Type: text/plain; charset="ISO-2022-JP"

{$body}
--{$boundary}
Content-Type: {$attachment_type}; name="{$attachment_name}"
Content-Disposition: attachment; filename="{$attachment_name}"
Content-Transfer-Encoding: base64

{$attachment_base64_chunk}
--{$boundary}--
EOS;
    return $body;
}

function update_history($send_count, $send_from) {
    $time_str = date("Y/m/d H:i:s");
    $raw = sprintf("%s,%s,%s\n", $time_str, $send_count, $send_from);

    $lines = file(HISTORY_CSV);
    if ($lines === false) {
        return file_put_contents(HISTORY_CSV, $raw);
    }

    // 古い履歴は削除する
    $too_old_utime = strtotime("1 month ago");
    foreach ($lines as $line) {
        $line = rtrim($line);
        list($date_str, $send_cnt, $send_from) = explode(",", $line);
        $utime = strtotime($date_str);
        if ($utime < $too_old_utime) {
            break;
        }
        $raw .= "{$line}\n";
    }

    return file_put_contents(HISTORY_CSV, $raw);
}



// from
$from = $_POST["from"];
if (empty($from)) {
    render_exit("送信元メールアドレスが指定されていません");
}
$from_name = empty($_POST["from_name"]) ? "" : $_POST["from_name"];

// タイトル
$title_tpl = $_POST["title"];

// 本文
$body_tpl = $_POST["body"];

// 添付ファイル
$enable_attachment = false;
if (
    !empty($_FILES["attachment"]) &&
    !empty($_FILES["attachment"]["name"]) &&
    !empty($_FILES["attachment"]["tmp_name"]) &&
    ($attachment_raw = file_get_contents($_FILES["attachment"]["tmp_name"])) !== false
) {
    $attachment_name = $_FILES["attachment"]["name"];
    $attachment_type = mime_content_type($_FILES["attachment"]["tmp_name"]);
    $enable_attachment = true;
}

// テスト送信
$test_send = false;
if (!empty($_POST["test_send"])) {
    $test_send = true;
}

// mail header
$boundary = "__BOUNDARY__";
$mail_headers["MIME-Version"] = "1.0";
if ($enable_attachment) {
    $mail_headers["Content-Type"] = "multipart/mixed;boundary=\"{$boundary}\"";
} else {
    $mail_headers["Content-Type"] = "text/plain; charset=UTF-8";
}
$mail_headers["Content-Transfer-Encoding"] = "8bit";
if (!empty($from_name)) {
    $mail_headers["From"] = '"'. mb_encode_mimeheader($from_name). '"<'. $from. '>';
} else {
    $mail_headers["From"] = $from;
}
$mail_headers_str = "";
foreach ($mail_headers as $key => $val) {
    $mail_headers_str .= "$key: $val\n";
}
$mail_headers_str = rtrim($mail_headers_str);
$mail_opt = "-f{$from}";

// csv
$mail = array();
if (!$test_send) {
    if (
        empty($_FILES["csv"]) ||
        empty($_FILES["csv"]["tmp_name"]) ||
        !($file = fopen($_FILES["csv"]["tmp_name"], "r"))
    ) {
        render_exit("csvファイルの読み込みに失敗しました");
    }
    $csv_encode = !empty($_POST["csv_c"]) ? $_POST["csv_c"] : "";
    while ($line = fgets($file)) {
        list($address, $name) = explode(",", $line);
        $address = trim($address);
        $name = trim($name);
        if (!empty($csv_encode)) {
            $name = mb_convert_encoding($name, "UTF-8", $csv_encode);
        } else {
            $detect = mb_detect_encoding($name, array('SJIS', 'EUC-JP', 'UTF-8'));
            $name = mb_convert_encoding($name, "UTF-8", $detect);
        }
        $mail[] = array($address, $name);
    }
    fclose($file);
    if (!$mail) {
        render_exit("メールアドレスと名前のペアが1件もありません");
    }
} else {
     $mail[] = array($from, $from_name);
}

// メール配信
$mail_address_fail = array();
$mail_address_success = array();
foreach ($mail as $mail_pair) {
    $address = $mail_pair[0];
    if (!is_valid_mailaddress($address)) {
        $mail_address_fail[] = $address;
        continue;
    }
    $name = $mail_pair[1];
    
    $title = replace_name($title_tpl, $name);
    $mail_title = build_mail_title($title);
    
    $body = replace_name($body_tpl, $name);
    if ($enable_attachment) {
        $body = build_body_include_attachment(
            $body,
            $attachment_type, $attachment_name, $attachment_raw,
            $boundary
        );
    }

    if (!mail($address, $mail_title, $body, $mail_headers_str, $mail_opt)) {
        $mail_address_fail[] = $address;
        continue;
    }
    $mail_address_success[] = $address;
}

if (!$test_send) {
    // 送信履歴
    update_history(count($mail_address_success), $from);

    // 完了画面
    render(count($mail_address_success). "件にメールを送信しました");
} else {
    // 完了画面
    render("テスト送信しました");
}
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
