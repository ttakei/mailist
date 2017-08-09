<?php
require_once("const.php");

function render($str = "", $display = false) {
    static $html = "";
    if (!empty($str)) {
        $html .= "<p>{$str}</p>";
    }

    if ($display) {
        $html = <<< EOS
<!DOCTYPE html>
<html lang="ja">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
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

function render_text_exit($str = "") {
    echo($str);
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

function build_attachment_file_name($file_name) {
    return "=?iso-2022-jp?B?". base64_encode(mb_convert_encoding($file_name, "JIS", "UTF-8")). "?=";
}

function build_body_include_attachment($body, $attachment_type, $attachment_name, $attachment_raw, $boundary) {
    $mail_attachment_name = build_attachment_file_name($attachment_name);
    $attachment_base64_chunk = chunk_split(base64_encode($attachment_raw));

    $body = <<< EOS
--{$boundary}
Content-Type: text/plain; charset="UTF-8"

{$body}
--{$boundary}
Content-Type: {$attachment_type}; name="{$mail_attachment_name}"
Content-Disposition: attachment; filename="{$mail_attachment_name}"
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
    render_exit("送信元メールアドレスが指定されていません。");
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
    if (!isset($_POST['send_to_name']) || !isset($_POST['send_to_address'])) {
        render_exit("メールアドレスと名前のデータが不正です。");
    }
    $name_arr = explode("\n", rtrim($_POST['send_to_name']));
    $address_arr = explode("\n", rtrim($_POST['send_to_address']));
    if (!is_array($name_arr) || !is_array($address_arr)) {
        render_exit("メールアドレスと名前のデータが不正です。");
    }
    if (count($name_arr) > count($address_arr)) {
        render_exit("メールアドレスの数が名前より少ないです。");
    }
    for ($i = 0; $i < count($address_arr); $i++) {
        $name = isset($name_arr[$i]) ? trim($name_arr[$i]) : "";
        $address = trim($address_arr[$i]);
        if ($name === "" && $address === "") {
            continue;
        }
        $mail[] = array($address, $name);
    }
    if (!$mail) {
        render_exit("メールアドレスと名前のペアが1件もありません。");
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
    render(count($mail_address_success). "件メールを送信しました。");
} else {
    // 完了画面
    render_text_exit("テスト送信しました。");
}
if ($mail_address_fail) {
    render("以下の宛先への送信に失敗しました。");
    $str = "<pre>";
    foreach ($mail_address_fail as $address) {
        $str .= "{$address}\n";
    }
    $str .= "</pre>";
    render($str);
}
render_exit();
