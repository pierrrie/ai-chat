#!/bin/bash
DOC=/var/www/a0601335/data/www/draxter.ru
echo "=== api log tail ==="
tail -n 5 "$DOC/upload/draxter_aichat_logs/api-2026-05-31.log" 2>/dev/null
echo "=== channel logs in session ==="
grep -l "email" "$DOC/upload/draxter_aichat_logs/"*.channels 2>/dev/null | tail -3
grep "email" "$DOC/upload/draxter_aichat_logs/"*.channels 2>/dev/null | tail -5
echo "=== b_option email ==="
mysql -N -e "SELECT NAME, VALUE FROM b_option WHERE MODULE_ID='draxter.aichat' AND NAME LIKE 'CRM_EMAIL%'" a0601335_draxter 2>/dev/null || echo "mysql skip"
mysql -N -e "SELECT VALUE FROM b_option WHERE MODULE_ID='main' AND NAME='email_from' LIMIT 1" a0601335_draxter 2>/dev/null || echo "mysql skip2"
echo "=== test mail via web bootstrap ==="
cd "$DOC/local/ajax"
php -r '
$_SERVER["DOCUMENT_ROOT"]=$doc="/var/www/a0601335/data/www/draxter.ru";
$_SERVER["HTTP_HOST"]="draxter.ru";
$_SERVER["REQUEST_URI"]="/local/ajax/draxter_aichat.php";
$_SERVER["SCRIPT_NAME"]="/local/ajax/draxter_aichat.php";
$_SERVER["SERVER_NAME"]="draxter.ru";
$_SERVER["HTTPS"]="on";
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
require $doc."/bitrix/modules/main/include/prolog_before.php";
\Bitrix\Main\Loader::includeModule("draxter.aichat");
use Bitrix\Main\Config\Option;
use Bitrix\Main\Mail\Mail;
use Draxter\Aichat\Settings;
echo "enabled=". (Settings::isCrmEmailEnabled()?"Y":"N")."\n";
echo "to=".Settings::get("CRM_EMAIL_TO","")."\n";
echo "from=".Option::get("main","email_from","")."\n";
$to=Settings::crmEmailRecipients();
if(!$to){echo "no recipients\n"; exit;}
$mail=["TO"=>implode(",",$to),"SUBJECT"=>"AI-chat test ".date("c"),"BODY"=>"<p>test</p>","CONTENT_TYPE"=>"text/html","CHARSET"=>"UTF-8","HEADER"=>[]];
$from=trim(Option::get("main","email_from",""));
if($from){$mail["FROM"]=$from;}
try{$sent=Mail::send($mail); echo "sent=".($sent?"ok":"false")."\n";}catch(Throwable $e){echo "err=".$e->getMessage()."\n";}
'
