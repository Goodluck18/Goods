<?php
declare(strict_types=1);
require_once __DIR__.'/api/pos_helpers.php';

$token=trim((string)($_GET['token']??''));
if($token==='' && isset($_SERVER['REQUEST_URI']) && preg_match('#/receipt/([a-f0-9]{64})#i',(string)$_SERVER['REQUEST_URI'],$m)) $token=$m[1];
if(!preg_match('/^[a-f0-9]{64}$/i',$token)) { http_response_code(404); echo 'Receipt not found.'; exit; }
try {
  $q=db()->prepare('SELECT * FROM pos_sales WHERE receipt_token_hash=? AND receipt_link_active=1 LIMIT 1');
  $q->execute([hash('sha256',strtolower($token))]); $sale=$q->fetch();
} catch(Throwable $e) {
  error_log('Goodlucks receipt lookup: '.$e->getMessage()); http_response_code(503); echo 'Receipts are temporarily unavailable. Please contact Goodlucks Goods.'; exit;
}
if(!$sale) { http_response_code(404); header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Receipt unavailable | Goodlucks Goods</title></head><body style="font-family:Arial,sans-serif;max-width:640px;margin:15vh auto;padding:24px;color:#252A34"><h1>Receipt unavailable</h1><p>This receipt link is invalid, expired, or has been revoked. Please contact Goodlucks Goods for assistance.</p><a href="https://shop.goodlucks.co">Visit Goodlucks Goods</a></body></html>'; exit; }
$items=pos_fetch_items((int)$sale['id']); $payments=pos_fetch_payments((int)$sale['id']); $settings=setting_map();
$businessName=(string)($settings['business_name']??'Goodlucks Goods');
$email=(string)($settings['business_email']??envv('GOODLUCKS_EMAIL','order@goodlucks.co'));
$phone=clean_phone((string)($settings['whatsapp_number']??envv('GOODLUCKS_WHATSAPP','+233202651646')));$phone=preg_replace('/\D+/','',$phone)?:'233202651646';
$site='https://shop.goodlucks.co';
$policy=(string)($sale['policy_snapshot']?:GOODLUCKS_RECEIPT_POLICY);
function rec_e(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function rec_money(mixed $v): string { return 'GH₵ '.number_format((float)$v,2,'.',','); }
function rec_delivery_location(array $sale): string { $city=trim((string)($sale['delivery_location']??'')); $address=trim((string)($sale['delivery_address']??'')); if($city!=='' && $address!=='' && strtolower($city)===strtolower($address)) return $city; return implode(', ',array_values(array_filter([$city,$address],static fn($v)=>$v!==''))); }
function rec_review_url(?string $url): ?string {
  $url=trim((string)$url); if($url==='')return null;
  if(!filter_var($url,FILTER_VALIDATE_URL))return null;
  $host=strtolower((string)parse_url($url,PHP_URL_HOST));$allowed=strtolower((string)parse_url('https://shop.goodlucks.co',PHP_URL_HOST));
  if($host!==$allowed || !in_array((string)parse_url($url,PHP_URL_SCHEME),['https','http'],true))return null;
  return $url.(str_contains($url,'?')?'&':'?').'openReview=1';
}
function rec_review_destination(?string $url): string { return rec_review_url($url) ?? 'https://shop.goodlucks.co'; }

function pdf_translit(string $text): string {
  $out=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$text);
  return $out===false?$text:$out;
}
function pdf_esc(string $text): string { return str_replace(["\\","(",")","\r","\n"],["\\\\","\\(","\\)",' ',' '],pdf_translit($text)); }
function pos_receipt_pdf(array $sale,array $items,array $payments,string $businessName,string $phone,string $email,string $site,string $policy): never {
  $root=dirname(__FILE__);$logoPath=$root.'/goodlucks-goods-logo-receipt.jpg';
  $logo=@file_get_contents($logoPath);$imgInfo=$logo?@getimagesize($logoPath):false;
  if(!$logo||!$imgInfo){ http_response_code(500); echo 'Receipt PDF logo asset is missing.'; exit; }
  $pageW=595;$pageH=842;$commands=[];$pageIndex=0;$y=0;$linkAnnotations=[];
  $addPdfLink=function(int $page,float $x1,float $top1,float $x2,float $top2,string $url)use(&$linkAnnotations,$pageH):void { $linkAnnotations[$page][]=[$x1,$pageH-$top2,$x2,$pageH-$top1,$url]; };
  $newPage=function(bool $continued=false)use(&$commands,&$pageIndex,&$y,$pageW,$pageH,$logo,$imgInfo,$businessName,$phone,$email,$site,$sale,$addPdfLink):void {
    $pageIndex++;$commands[$pageIndex]='';
    $imgW=135;$imgH=40;$imgY=$pageH-22-$imgH;
    $commands[$pageIndex].='q '.$imgW.' 0 0 '.$imgH.' 32 '.$imgY.' cm /Logo Do Q'."\n";
    $head=$continued?'SALES RECEIPT — CONTINUED':'SALES RECEIPT';
    $commands[$pageIndex].='BT /F2 14 Tf 0.145 0.165 0.204 rg 1 0 0 1 340 '.($pageH-39-14).' Tm ('.pdf_esc($head).') Tj ET'."\n";
    $commands[$pageIndex].='BT /F1 8 Tf 0.25 0.27 0.31 rg 1 0 0 1 340 '.($pageH-55-8).' Tm ('.pdf_esc($businessName).') Tj ET'."\n";
    $commands[$pageIndex].='BT /F1 8 Tf 0.25 0.27 0.31 rg 1 0 0 1 340 '.($pageH-68-8).' Tm ('.pdf_esc($phone.' | '.$email).') Tj ET'."\n";
    $commands[$pageIndex].='BT /F1 8 Tf 0.25 0.27 0.31 rg 1 0 0 1 340 '.($pageH-81-8).' Tm ('.pdf_esc($site).') Tj ET'."\n";
    $addPdfLink($pageIndex,338,70,563,91,$site);
    $commands[$pageIndex].='0.031 0.851 0.839 RG 1.8 w 32 '.($pageH-94).' m 563 '.($pageH-94).' l S'."\n";
    if(!$continued){
      $fields=[['Receipt reference',$sale['sale_reference']??''],['Date',date('d M Y, H:i',strtotime((string)($sale['transaction_at']??$sale['created_at'])))],['Customer',trim((string)($sale['customer_name']??''))?:'Walk-in customer'],['Phone / email',trim((string)($sale['customer_phone']??'').' '.(string)($sale['customer_email']??''))],['Sales channel',ucwords(str_replace('_',' ',(string)$sale['sale_channel']))],['Delivery location',rec_delivery_location($sale)?:'Not specified']];
      $top=113;foreach($fields as $i=>$field){$col=$i%2;$row=intdiv($i,2);$x=$col===0?35:310;$t=$top+$row*20;$commands[$pageIndex].='BT /F2 8 Tf 0.30 0.33 0.38 rg 1 0 0 1 '.$x.' '.($pageH-$t-8).' Tm ('.pdf_esc($field[0].':').') Tj ET'."\n";$commands[$pageIndex].='BT /F1 9 Tf 0.145 0.165 0.204 rg 1 0 0 1 '.($x+70).' '.($pageH-$t-9).' Tm ('.pdf_esc(substr(pdf_translit((string)$field[1]),0,48)).') Tj ET'."\n";}
      $y=180;
    } else { $y=110; }
    $commands[$pageIndex].='0.91 0.93 0.94 rg 32 '.($pageH-$y-24).' 531 24 re f'."\n";
    $cols=[[40,'Item'],[370,'Qty'],[420,'Unit price'],[500,'Amount']];foreach($cols as [$x,$label])$commands[$pageIndex].='BT /F2 8 Tf 0.145 0.165 0.204 rg 1 0 0 1 '.$x.' '.($pageH-$y-15).' Tm ('.pdf_esc($label).') Tj ET'."\n";
    $y+=28;
  };
  $drawText=function(float $x,float $top,string $text,float $size=8,bool $bold=false)use(&$commands,&$pageIndex,$pageH):void {$font=$bold?'/F2':'/F1';$commands[$pageIndex].='BT '.$font.' '.$size.' Tf 0.145 0.165 0.204 rg 1 0 0 1 '.$x.' '.($pageH-$top-$size).' Tm ('.pdf_esc($text).') Tj ET'."\n";};
  $drawLine=function(float $top)use(&$commands,&$pageIndex,$pageH):void {$commands[$pageIndex].='0.88 0.90 0.92 RG 0.5 w 32 '.($pageH-$top).' m 563 '.($pageH-$top).' l S'."\n";};
  $newPage(false);
  foreach($items as $i=>$it){
    $name=(string)$it['productName'];$nameLines=explode("\n",wordwrap($name,43,"\n",true));$nameLines=array_slice($nameLines,0,2);$rowH=max(count($nameLines)>1?34:25,!empty($it['description'])?34:25)+12;
    if($y+$rowH>575){$newPage(true);}
    $drawText(40,$y,$nameLines[0],8,true);if(isset($nameLines[1]))$drawText(40,$y+10,$nameLines[1],7,false);
    if(!empty($it['description']))$drawText(40,$y+(isset($nameLines[1])?20:10),substr(pdf_translit((string)$it['description']),0,48),6.5,false);
    $reviewDestination=rec_review_destination($it['productUrl']??null);$drawText(40,$y+$rowH-9,'Review Product',6.5,true);$addPdfLink($pageIndex,38,$y+$rowH-11,110,$y+$rowH-1,$reviewDestination);
    $drawText(379,$y,(string)$it['quantity'],8,false);$drawText(420,$y,number_format((float)$it['unitPrice'],2,'.',','),8,false);$drawText(500,$y,number_format((float)$it['lineTotal'],2,'.',','),8,true);
    $y+=$rowH;$drawLine($y-2);
  }
  $needed=150;if($y+$needed>800)$newPage(true);
  $y+=8;
  $summary=[['Subtotal',(float)$sale['subtotal']],['Discount',-(float)$sale['discount_amount']],['Delivery fee',(float)$sale['delivery_fee']],['Total amount due',(float)$sale['total_amount']],['Total paid',(float)$sale['amount_paid']],['Outstanding balance',(float)$sale['outstanding_balance']]];
  foreach($summary as $ix=>$row){$strong=$ix===3||$ix===5;$size=$strong?10:8;$drawText(365,$y,$row[0],$size,$strong);$amt=$row[1]<0?'-'.number_format(abs($row[1]),2,'.',','):number_format($row[1],2,'.',',');$drawText(492,$y,'GH'.'S '.$amt,$size,$strong);if($ix===3)$drawLine($y+13);$y+=$strong?20:16;}
  $status=['pending'=>'Pending Payment','partial'=>'Partially Paid','paid'=>'Fully Paid'][(string)$sale['payment_status']]??ucfirst((string)$sale['payment_status']);
  $drawText(365,$y,'Payment status: '.$status,8,true);$y+=23;
  if($payments){$drawText(35,$y,'PAYMENT HISTORY',8,true);$y+=13;foreach($payments as $pay){if($y>770){$newPage(true);$y=115;}$drawText(40,$y,date('d M Y H:i',strtotime((string)$pay['receivedAt'])).' • '.ucfirst((string)$pay['method']),7,false);$drawText(450,$y,'GHS '.number_format((float)$pay['amount'],2,'.',','),7,false);$y+=12;}}
  if($y+85>790){$newPage(true);$y=115;}
  $commands[$pageIndex].='0.91 0.93 0.94 rg 32 '.($pageH-$y-17).' 531 17 re f'."\n";
  $drawText(40,$y+4,'RETURN, REFUND & WARRANTY POLICY',7,true);$y+=25;
  // Preserve the complete approved policy, wrap naturally, and continue onto additional pages.
  $policyLines=[];foreach(preg_split('/\r?\n/', $policy) as $paragraph){$wrapped=wordwrap(trim($paragraph),145,"\n",true);foreach(explode("\n",$wrapped) as $line)$policyLines[]=$line; if(trim($paragraph)==='')$policyLines[]='';}
  foreach($policyLines as $line){if($y>785){$newPage(true);$y=112;}$drawText(35,$y,$line,7.2,false);$y+=11;}
  $y+=15;
  if($y>785){$newPage(true);$y=115;}
  $thank='Thank you for shopping with Goodlucks Goods.';$approxWidth=strlen($thank)*3.35;$thankX=max(35,($pageW-$approxWidth)/2);
  $commands[$pageIndex].='BT /F1 7 Tf 0.47 0.50 0.54 rg 1 0 0 1 '.$thankX.' '.($pageH-$y-7).' Tm ('.pdf_esc($thank).') Tj ET'."\n";$y+=25;
  if($y+25>815){$newPage(true);$y=115;}
  $linksTop=$y;
  $pdfLinkText=function(float $x,float $top,string $label,string $url,float $width)use(&$commands,&$pageIndex,$pageH,$addPdfLink):void { $commands[$pageIndex].='BT /F2 8 Tf 1 0.18 0.39 rg 1 0 0 1 '.$x.' '.($pageH-$top-8).' Tm ('.pdf_esc($label).') Tj ET'."\n"; $addPdfLink($pageIndex,$x-2,$top-2,min(563,$x+$width),$top+11,$url); };
  $pdfLinkText(35,$linksTop,'Check Current Receipt Status',(string)($GLOBALS['goodlucksPdfReceiptUrl']??$site),155);
  $pdfLinkText(220,$linksTop,'Shop More','https://shop.goodlucks.co',65);
  $pdfLinkText(315,$linksTop,'Review Product(s)',rec_review_destination($items[0]['productUrl']??null),100);

  $objects=[];$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
  $pageIds=[];$contentIds=[];$next=6;foreach($commands as $idx=>$content){$pageIds[]=$next;$contentIds[]=$next+1;$next+=2;}
  $kids=implode(' ',array_map(static fn($id)=>$id.' 0 R',$pageIds));$objects[2]='<< /Type /Pages /Kids ['.$kids.'] /Count '.count($pageIds).' >>';
  $objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
  $objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
  $objects[5]='<< /Type /XObject /Subtype /Image /Width '.$imgInfo[0].' /Height '.$imgInfo[1].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($logo)." >>\nstream\n".$logo."\nendstream";
  $ix=0;foreach($commands as $content){$pid=$pageIds[$ix];$cid=$contentIds[$ix];$annots=[];foreach(($linkAnnotations[$ix+1]??[]) as $lnk){$aid=$next++;[$x1,$y1,$x2,$y2,$url]=$lnk;$objects[$aid]='<< /Type /Annot /Subtype /Link /Rect ['.$x1.' '.$y1.' '.$x2.' '.$y2.'] /Border [0 0 0] /A << /S /URI /URI ('.pdf_esc($url).') >> >>';$annots[]=$aid.' 0 R';}$annotsPart=$annots?' /Annots ['.implode(' ',$annots).']':'';$objects[$pid]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$pageW.' '.$pageH.'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Logo 5 0 R >> >> /Contents '.$cid.' 0 R'.$annotsPart.' >>';$objects[$cid]='<< /Length '.strlen($content).">>\nstream\n".$content."endstream";$ix++;}
  ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];$max=max(array_keys($objects));for($i=1;$i<=$max;$i++){$offsets[$i]=strlen($pdf);$pdf.=$i." 0 obj\n".$objects[$i]."\nendobj\n";}
  $xref=strlen($pdf);$pdf.='xref'."\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',(int)$offsets[$i])."\n";
  $pdf.='trailer << /Size '.($max+1).' /Root 1 0 R >>' ."\nstartxref\n".$xref."\n%%EOF";
  header('Content-Type: application/pdf');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9-]/','-',(string)$sale['sale_reference']).'.pdf"');header('Content-Length: '.strlen($pdf));header('Cache-Control: private, no-store');echo $pdf;exit;
}

if(($_GET['format']??'')==='pdf') { $GLOBALS['goodlucksPdfReceiptUrl']=pos_receipt_url($token); pos_receipt_pdf($sale,$items,$payments,$businessName,$phone,$email,$site,$policy); }

$reviewLinks=[];foreach($items as $it){$reviewLinks[]=['name'=>$it['productName'],'url'=>rec_review_destination($it['productUrl']??null)];}
$waText='Hello Goodlucks Goods, I have a question about receipt '.$sale['sale_reference'].'.';
$waUrl='https://wa.me/'.preg_replace('/[^0-9]/','',$phone).'?text='.rawurlencode($waText);
header('Content-Type: text/html; charset=utf-8');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('Cache-Control: private, no-store');
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Your Goodlucks Goods Receipt | Check Payment Status</title>
<meta name="description" content="View your Goodlucks Goods receipt, check your payment status, and see your outstanding balance online.">
<meta name="robots" content="noindex,nofollow,noarchive"><meta name="theme-color" content="#252A34">
<meta property="og:title" content="Your Goodlucks Goods Receipt | Check Payment Status">
<meta property="og:description" content="View your Goodlucks Goods receipt, check your payment status, and see your outstanding balance online.">
<meta property="og:url" content="<?=rec_e(rtrim(pos_origin(), '/').'/')?>"><meta property="og:type" content="website">
<meta property="og:image" content="<?=rec_e(rtrim(pos_origin(),'/').'/receipt-preview.png')?>">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="Your Goodlucks Goods Receipt | Check Payment Status">
<meta name="twitter:description" content="View your Goodlucks Goods receipt, check your payment status, and see your outstanding balance online."><meta name="twitter:image" content="<?=rec_e(rtrim(pos_origin(),'/').'/receipt-preview.png')?>">
<style>
:root{--teal:#08D9D6;--pink:#FF2E63;--dark:#252A34;--grey:#EAEAEA}*{box-sizing:border-box}body{margin:0;background:#f4f6f8;color:var(--dark);font:14px/1.45 Arial,Helvetica,sans-serif}.wrap{max-width:940px;margin:28px auto;padding:0 16px}.actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-bottom:12px}.btn{border:0;border-radius:8px;padding:10px 13px;font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;cursor:pointer}.btn-primary{background:var(--dark);color:#fff}.btn-secondary{background:#fff;color:var(--dark);border:1px solid #d7dce2}.receipt{background:#fff;border:1px solid #e2e6ea;border-radius:12px;padding:28px 32px;box-shadow:0 8px 28px #252a3410}.head{display:flex;gap:24px;justify-content:space-between;align-items:center;border-bottom:3px solid var(--teal);padding-bottom:18px}.logo{width:190px;max-height:66px;object-fit:contain}.company{text-align:right;font-size:12px;line-height:1.6;color:#505765}.doctype{font-size:22px;letter-spacing:.04em;font-weight:800;margin:18px 0 12px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:8px 26px;margin-bottom:22px}.field{display:flex;gap:8px;min-width:0}.field span{color:#6c7480;min-width:112px;font-size:12px}.field strong{font-size:12px;overflow-wrap:anywhere}.status{display:inline-block;border-radius:99px;padding:3px 9px;background:#edf8f7;color:#075b59;font-size:11px;font-weight:800}.table-wrap{width:100%;overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:12px}thead{background:#f0f3f5}th{font-size:10px;text-transform:uppercase;letter-spacing:.04em;text-align:left;padding:10px 9px;color:#5a626c}td{padding:11px 9px;border-bottom:1px solid #edf0f2;vertical-align:top}td.num,th.num{text-align:right;white-space:nowrap}.item-name{font-weight:700}.item-note{color:#737b86;font-size:11px;margin-top:3px;white-space:pre-line}.summary{margin:18px 0 0 auto;width:min(100%,340px)}.sumrow{display:flex;justify-content:space-between;gap:16px;padding:5px 0;font-size:12px}.sumrow.total{border-top:1px solid #cbd2d9;margin-top:5px;padding-top:10px;font-size:15px;font-weight:800}.sumrow.balance{font-weight:800;color:var(--pink)}.paystatus{margin-top:10px;text-align:right}.history{margin-top:16px;border-top:1px solid #e5e8eb;padding-top:12px}.section-title{font-size:10px;font-weight:800;letter-spacing:.06em;margin:0 0 8px}.history-row{display:flex;gap:10px;justify-content:space-between;padding:4px 0;color:#5f6771;font-size:11px}.policy{border-top:1px solid var(--grey);margin-top:22px;padding-top:14px}.policy p{font-size:10px;color:#515966;margin:0}.footer{font-size:11px;color:#777f89;text-align:center;margin-top:20px}.retention{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:center;background:#fff;border:1px solid #e1e5e9;border-radius:12px;margin:14px 0 0;padding:14px}.retention a{color:var(--dark);font-weight:700;font-size:12px;text-decoration:none;border-bottom:1px solid var(--teal);padding:4px 1px}.reviewbox{background:#fff;border:1px solid #e1e5e9;border-radius:12px;padding:16px;margin-top:14px}.reviewbox ul{list-style:none;padding:0;margin:0}.reviewbox li{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #f0f2f4;padding:8px 0}.reviewbox li:last-child{border:0}.reviewbox a{color:#007f7d;font-weight:800;white-space:nowrap}.muted{color:#777f89;font-size:11px}@media(max-width:600px){.wrap{margin:12px auto;padding:0 9px}.receipt{padding:17px 13px;border-radius:9px}.head{align-items:flex-start;gap:10px}.logo{width:120px}.company{font-size:10px}.doctype{font-size:18px}.meta{grid-template-columns:1fr;gap:7px}.field span{min-width:100px}th,td{padding:9px 6px}.summary{width:100%}.actions .btn{flex:1}.reviewbox li{align-items:flex-start}}@media print{body{background:#fff}.wrap{max-width:none;margin:0;padding:0}.actions,.retention,.reviewbox{display:none!important}.receipt{border:0;border-radius:0;box-shadow:none;padding:0}.policy{break-inside:avoid}.table-wrap{overflow:visible}thead{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style></head><body>
<div class="wrap">
  <div class="actions"><button class="btn btn-secondary" onclick="window.print()">Print receipt</button><a class="btn btn-secondary" href="<?=rec_e(pos_receipt_url($token))?>">Check Current Receipt Status</a><a class="btn btn-primary" href="<?=rec_e('/receipt.php?token='.rawurlencode($token).'&format=pdf')?>">Download PDF</a></div>
  <main class="receipt" id="receipt">
    <header class="head"><img class="logo" src="/goodlucks-goods-logo.svg" alt="Goodlucks Goods logo"><div class="company"><strong><?=rec_e($businessName)?></strong><br>WhatsApp: +<?=rec_e($phone)?><br><?=rec_e($email)?><br><a href="<?=rec_e($site)?>" style="color:inherit"><?=rec_e(parse_url($site,PHP_URL_HOST)?:$site)?></a></div></header>
    <h1 class="doctype">SALES RECEIPT</h1>
    <section class="meta">
      <div class="field"><span>Receipt reference</span><strong><?=rec_e($sale['sale_reference'])?></strong></div>
      <div class="field"><span>Date</span><strong><?=rec_e(date('d M Y, H:i',strtotime((string)($sale['transaction_at']??$sale['created_at']))))?></strong></div>
      <div class="field"><span>Customer</span><strong><?=rec_e(trim((string)$sale['customer_name'])?:'Walk-in customer')?></strong></div>
      <div class="field"><span>Phone</span><strong><?=rec_e($sale['customer_phone']??'—')?></strong></div>
      <?php if(!empty($sale['customer_email'])): ?><div class="field"><span>Email</span><strong><?=rec_e($sale['customer_email'])?></strong></div><?php endif; ?>
      <?php if(rec_delivery_location($sale)!==''): ?><div class="field"><span>Delivery Location</span><strong><?=rec_e(rec_delivery_location($sale))?></strong></div><?php endif; ?>
      <div class="field"><span>Sales channel</span><strong><?=rec_e(ucwords(str_replace('_',' ',(string)$sale['sale_channel'])))?></strong></div>
      <div class="field"><span>Payment status</span><strong><span class="status"><?=rec_e(['pending'=>'Pending Payment','partial'=>'Partially Paid','paid'=>'Fully Paid'][(string)$sale['payment_status']]??$sale['payment_status'])?></span></strong></div>
    </section>
    <div class="table-wrap"><table><thead><tr><th>Item description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead><tbody>
    <?php foreach($items as $it): ?><tr><td><div class="item-name"><?=rec_e($it['productName'])?></div><?php if(!empty($it['productReference'])): ?><div class="item-note">Ref: <?=rec_e($it['productReference'])?></div><?php endif; ?><?php if(!empty($it['description'])): ?><div class="item-note"><?=rec_e($it['description'])?></div><?php endif; ?></td><td class="num"><?=rec_e($it['quantity'])?></td><td class="num"><?=rec_e(rec_money($it['unitPrice']))?></td><td class="num"><?=rec_e(rec_money($it['lineTotal']))?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <section class="summary">
      <div class="sumrow"><span>Subtotal</span><strong><?=rec_e(rec_money($sale['subtotal']))?></strong></div>
      <?php if((float)$sale['discount_amount']>0): ?><div class="sumrow"><span>Discount</span><strong>−<?=rec_e(rec_money($sale['discount_amount']))?></strong></div><?php endif; ?>
      <?php if((float)$sale['delivery_fee']>0): ?><div class="sumrow"><span>Delivery fee</span><strong><?=rec_e(rec_money($sale['delivery_fee']))?></strong></div><?php endif; ?>
      <div class="sumrow total"><span>Total amount due</span><strong><?=rec_e(rec_money($sale['total_amount']))?></strong></div>
      <div class="sumrow"><span>Total paid</span><strong><?=rec_e(rec_money($sale['amount_paid']))?></strong></div>
      <div class="sumrow balance"><span>Outstanding balance</span><strong><?=rec_e(rec_money($sale['outstanding_balance']))?></strong></div>
      <div class="paystatus"><span class="status"><?=rec_e(['pending'=>'Pending Payment','partial'=>'Partially Paid','paid'=>'Fully Paid'][(string)$sale['payment_status']]??$sale['payment_status'])?></span></div>
    </section>
    <?php if($payments): ?><section class="history"><h2 class="section-title">PAYMENT HISTORY</h2><?php foreach($payments as $payment): ?><div class="history-row"><span><?=rec_e(date('d M Y, H:i',strtotime((string)$payment['receivedAt'])))?> · <?=rec_e(ucfirst((string)$payment['method']))?><?php if(!empty($payment['reference'])): ?> · <?=rec_e($payment['reference'])?><?php endif; ?></span><strong><?=rec_e(rec_money($payment['amount']))?></strong></div><?php endforeach; ?></section><?php endif; ?>
    <section class="policy"><h2 class="section-title">RETURN, REFUND &amp; WARRANTY POLICY</h2><p><?=rec_e($policy)?></p></section>
    <div class="footer">Thank you for shopping with Goodlucks Goods.</div>
  </main>
  <?php if($reviewLinks): ?><section class="reviewbox"><h2 class="section-title">LEAVE A PRODUCT REVIEW</h2><ul><?php foreach($reviewLinks as $review): ?><li><span><?=rec_e($review['name'])?></span><a href="<?=rec_e($review['url'])?>">Review this item →</a></li><?php endforeach; ?></ul></section><?php endif; ?>
  <nav class="retention" aria-label="Customer links"><a href="https://shop.goodlucks.co">Shop more</a><a href="<?=rec_e($reviewLinks[0]['url']??'https://shop.goodlucks.co')?>">Review Product</a><a href="<?=rec_e($waUrl)?>" target="_blank" rel="noopener">Contact Goodlucks Goods on WhatsApp</a></nav>
</div>
</body></html>
