<?php
// V56 — étend countries_config avec ~140 pays principaux (ISO 3166-1).
// IP-whitelist VPS atelier. Idempotent : INSERT IGNORE + UPDATE enabled=1 si existant.
require_once __DIR__ . '/db.php';
$allowed = ['46.225.215.148','127.0.0.1','::1'];
$remote = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
$ip = trim(explode(',', $remote)[0]);
if (!in_array($ip, $allowed, true)) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'forbidden','seen_ip'=>$ip])); }
header('Content-Type: application/json; charset=utf-8');

try { db()->exec("CREATE TABLE IF NOT EXISTS countries_config (
    code CHAR(2) PRIMARY KEY, name VARCHAR(60), flag_emoji VARCHAR(10),
    currency VARCHAR(4), devise_symbol VARCHAR(10), phone_prefix VARCHAR(6),
    enabled TINYINT NOT NULL DEFAULT 1, sort_order INT DEFAULT 100
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); } catch (Throwable $e) {}

// [code, name, flag, currency, symbol, dial, enabled, sort_order]
$pays = [
    // Favoris (sort 1-50)
    ['FR','France','🇫🇷','EUR','€','33',1,1],
    ['MA','Maroc','🇲🇦','MAD','MAD','212',1,2],
    ['ES','Espagne','🇪🇸','EUR','€','34',1,3],
    ['BE','Belgique','🇧🇪','EUR','€','32',1,4],
    ['CH','Suisse','🇨🇭','CHF','CHF','41',1,5],
    ['GB','Royaume-Uni','🇬🇧','GBP','£','44',1,6],
    ['US','États-Unis','🇺🇸','USD','$','1',1,7],
    ['AE','Émirats arabes unis','🇦🇪','AED','AED','971',1,8],
    // Europe (sort 100+)
    ['DE','Allemagne','🇩🇪','EUR','€','49',1,100],
    ['IT','Italie','🇮🇹','EUR','€','39',1,101],
    ['PT','Portugal','🇵🇹','EUR','€','351',1,102],
    ['NL','Pays-Bas','🇳🇱','EUR','€','31',1,103],
    ['LU','Luxembourg','🇱🇺','EUR','€','352',1,104],
    ['IE','Irlande','🇮🇪','EUR','€','353',1,105],
    ['AT','Autriche','🇦🇹','EUR','€','43',1,106],
    ['DK','Danemark','🇩🇰','DKK','kr','45',1,107],
    ['SE','Suède','🇸🇪','SEK','kr','46',1,108],
    ['NO','Norvège','🇳🇴','NOK','kr','47',1,109],
    ['FI','Finlande','🇫🇮','EUR','€','358',1,110],
    ['IS','Islande','🇮🇸','ISK','kr','354',1,111],
    ['PL','Pologne','🇵🇱','PLN','zł','48',1,112],
    ['CZ','Tchéquie','🇨🇿','CZK','Kč','420',1,113],
    ['SK','Slovaquie','🇸🇰','EUR','€','421',1,114],
    ['HU','Hongrie','🇭🇺','HUF','Ft','36',1,115],
    ['RO','Roumanie','🇷🇴','RON','lei','40',1,116],
    ['BG','Bulgarie','🇧🇬','BGN','лв','359',1,117],
    ['GR','Grèce','🇬🇷','EUR','€','30',1,118],
    ['HR','Croatie','🇭🇷','EUR','€','385',1,119],
    ['SI','Slovénie','🇸🇮','EUR','€','386',1,120],
    ['LT','Lituanie','🇱🇹','EUR','€','370',1,121],
    ['LV','Lettonie','🇱🇻','EUR','€','371',1,122],
    ['EE','Estonie','🇪🇪','EUR','€','372',1,123],
    ['CY','Chypre','🇨🇾','EUR','€','357',1,124],
    ['MT','Malte','🇲🇹','EUR','€','356',1,125],
    ['MC','Monaco','🇲🇨','EUR','€','377',1,126],
    ['AD','Andorre','🇦🇩','EUR','€','376',1,127],
    ['VA','Vatican','🇻🇦','EUR','€','379',1,128],
    ['SM','Saint-Marin','🇸🇲','EUR','€','378',1,129],
    ['LI','Liechtenstein','🇱🇮','CHF','CHF','423',1,130],
    ['AL','Albanie','🇦🇱','ALL','L','355',1,131],
    ['BA','Bosnie-Herzégovine','🇧🇦','BAM','KM','387',1,132],
    ['ME','Monténégro','🇲🇪','EUR','€','382',1,133],
    ['MK','Macédoine du Nord','🇲🇰','MKD','ден','389',1,134],
    ['RS','Serbie','🇷🇸','RSD','дин','381',1,135],
    ['XK','Kosovo','🇽🇰','EUR','€','383',1,136],
    ['UA','Ukraine','🇺🇦','UAH','₴','380',1,137],
    ['BY','Bélarus','🇧🇾','BYN','Br','375',1,138],
    ['MD','Moldavie','🇲🇩','MDL','L','373',1,139],
    ['RU','Russie','🇷🇺','RUB','₽','7',1,140],
    ['TR','Turquie','🇹🇷','TRY','₺','90',1,141],
    // Maghreb / Afrique (sort 200+)
    ['DZ','Algérie','🇩🇿','DZD','DA','213',1,200],
    ['TN','Tunisie','🇹🇳','TND','DT','216',1,201],
    ['LY','Libye','🇱🇾','LYD','LD','218',1,202],
    ['EG','Égypte','🇪🇬','EGP','£E','20',1,203],
    ['SN','Sénégal','🇸🇳','XOF','CFA','221',1,204],
    ['CI','Côte d\'Ivoire','🇨🇮','XOF','CFA','225',1,205],
    ['CM','Cameroun','🇨🇲','XAF','CFA','237',1,206],
    ['BF','Burkina Faso','🇧🇫','XOF','CFA','226',1,207],
    ['ML','Mali','🇲🇱','XOF','CFA','223',1,208],
    ['NE','Niger','🇳🇪','XOF','CFA','227',1,209],
    ['BJ','Bénin','🇧🇯','XOF','CFA','229',1,210],
    ['TG','Togo','🇹🇬','XOF','CFA','228',1,211],
    ['GA','Gabon','🇬🇦','XAF','CFA','241',1,212],
    ['CG','Congo','🇨🇬','XAF','CFA','242',1,213],
    ['CD','RD Congo','🇨🇩','CDF','FC','243',1,214],
    ['MG','Madagascar','🇲🇬','MGA','Ar','261',1,215],
    ['MU','Maurice','🇲🇺','MUR','₨','230',1,216],
    ['KE','Kenya','🇰🇪','KES','KSh','254',1,217],
    ['NG','Nigeria','🇳🇬','NGN','₦','234',1,218],
    ['ZA','Afrique du Sud','🇿🇦','ZAR','R','27',1,219],
    ['ET','Éthiopie','🇪🇹','ETB','Br','251',1,220],
    ['GH','Ghana','🇬🇭','GHS','₵','233',1,221],
    ['RW','Rwanda','🇷🇼','RWF','FRw','250',1,222],
    ['TZ','Tanzanie','🇹🇿','TZS','TSh','255',1,223],
    ['UG','Ouganda','🇺🇬','UGX','USh','256',1,224],
    // Moyen-Orient (sort 300+)
    ['SA','Arabie saoudite','🇸🇦','SAR','SAR','966',1,300],
    ['QA','Qatar','🇶🇦','QAR','QAR','974',1,301],
    ['KW','Koweït','🇰🇼','KWD','KD','965',1,302],
    ['BH','Bahreïn','🇧🇭','BHD','BD','973',1,303],
    ['OM','Oman','🇴🇲','OMR','OR','968',1,304],
    ['JO','Jordanie','🇯🇴','JOD','JD','962',1,305],
    ['LB','Liban','🇱🇧','LBP','LL','961',1,306],
    ['IL','Israël','🇮🇱','ILS','₪','972',1,307],
    ['PS','Palestine','🇵🇸','ILS','₪','970',1,308],
    ['SY','Syrie','🇸🇾','SYP','SP','963',1,309],
    ['IQ','Irak','🇮🇶','IQD','ID','964',1,310],
    ['IR','Iran','🇮🇷','IRR','﷼','98',1,311],
    ['YE','Yémen','🇾🇪','YER','﷼','967',1,312],
    // Asie (sort 400+)
    ['CN','Chine','🇨🇳','CNY','¥','86',1,400],
    ['JP','Japon','🇯🇵','JPY','¥','81',1,401],
    ['KR','Corée du Sud','🇰🇷','KRW','₩','82',1,402],
    ['HK','Hong Kong','🇭🇰','HKD','HK$','852',1,403],
    ['TW','Taïwan','🇹🇼','TWD','NT$','886',1,404],
    ['SG','Singapour','🇸🇬','SGD','S$','65',1,405],
    ['MY','Malaisie','🇲🇾','MYR','RM','60',1,406],
    ['ID','Indonésie','🇮🇩','IDR','Rp','62',1,407],
    ['PH','Philippines','🇵🇭','PHP','₱','63',1,408],
    ['TH','Thaïlande','🇹🇭','THB','฿','66',1,409],
    ['VN','Vietnam','🇻🇳','VND','₫','84',1,410],
    ['IN','Inde','🇮🇳','INR','₹','91',1,411],
    ['PK','Pakistan','🇵🇰','PKR','₨','92',1,412],
    ['BD','Bangladesh','🇧🇩','BDT','৳','880',1,413],
    ['LK','Sri Lanka','🇱🇰','LKR','₨','94',1,414],
    ['NP','Népal','🇳🇵','NPR','₨','977',1,415],
    ['AF','Afghanistan','🇦🇫','AFN','؋','93',1,416],
    ['KH','Cambodge','🇰🇭','KHR','៛','855',1,417],
    ['LA','Laos','🇱🇦','LAK','₭','856',1,418],
    ['MM','Birmanie','🇲🇲','MMK','K','95',1,419],
    ['MO','Macao','🇲🇴','MOP','MOP$','853',1,420],
    ['MN','Mongolie','🇲🇳','MNT','₮','976',1,421],
    ['KZ','Kazakhstan','🇰🇿','KZT','₸','7',1,422],
    ['UZ','Ouzbékistan','🇺🇿','UZS','лв','998',1,423],
    // Amériques (sort 500+)
    ['CA','Canada','🇨🇦','CAD','$CA','1',1,500],
    ['MX','Mexique','🇲🇽','MXN','$','52',1,501],
    ['BR','Brésil','🇧🇷','BRL','R$','55',1,502],
    ['AR','Argentine','🇦🇷','ARS','$','54',1,503],
    ['CL','Chili','🇨🇱','CLP','$','56',1,504],
    ['CO','Colombie','🇨🇴','COP','$','57',1,505],
    ['PE','Pérou','🇵🇪','PEN','S/','51',1,506],
    ['VE','Venezuela','🇻🇪','VES','Bs','58',1,507],
    ['UY','Uruguay','🇺🇾','UYU','$U','598',1,508],
    ['PY','Paraguay','🇵🇾','PYG','₲','595',1,509],
    ['BO','Bolivie','🇧🇴','BOB','Bs','591',1,510],
    ['EC','Équateur','🇪🇨','USD','$','593',1,511],
    ['CR','Costa Rica','🇨🇷','CRC','₡','506',1,512],
    ['PA','Panama','🇵🇦','PAB','B/.','507',1,513],
    ['DO','Rép. dominicaine','🇩🇴','DOP','RD$','1809',1,514],
    ['CU','Cuba','🇨🇺','CUP','$','53',1,515],
    ['HT','Haïti','🇭🇹','HTG','G','509',1,516],
    ['JM','Jamaïque','🇯🇲','JMD','J$','1876',1,517],
    // Océanie (sort 600+)
    ['AU','Australie','🇦🇺','AUD','A$','61',1,600],
    ['NZ','Nouvelle-Zélande','🇳🇿','NZD','NZ$','64',1,601],
    ['PF','Polynésie française','🇵🇫','XPF','F','689',1,602],
    ['NC','Nouvelle-Calédonie','🇳🇨','XPF','F','687',1,603],
    ['FJ','Fidji','🇫🇯','FJD','FJ$','679',1,604],
    // DOM-TOM FR (sort 700+)
    ['RE','La Réunion','🇷🇪','EUR','€','262',1,700],
    ['GP','Guadeloupe','🇬🇵','EUR','€','590',1,701],
    ['MQ','Martinique','🇲🇶','EUR','€','596',1,702],
    ['GF','Guyane','🇬🇫','EUR','€','594',1,703],
    ['YT','Mayotte','🇾🇹','EUR','€','262',1,704],
    ['BL','Saint-Barthélemy','🇧🇱','EUR','€','590',1,705],
    ['MF','Saint-Martin','🇲🇫','EUR','€','590',1,706],
    ['PM','Saint-Pierre-et-Miquelon','🇵🇲','EUR','€','508',1,707],
    ['WF','Wallis-et-Futuna','🇼🇫','XPF','F','681',1,708],
    ['TF','Terres australes','🇹🇫','EUR','€','262',1,709],
];

$inserted = 0; $updated = 0;
$pdo = db();
$ins = $pdo->prepare("INSERT IGNORE INTO countries_config (code,name,flag_emoji,currency,devise_symbol,phone_prefix,enabled,sort_order) VALUES (?,?,?,?,?,?,?,?)");
$upd = $pdo->prepare("UPDATE countries_config SET name=?, flag_emoji=?, currency=?, devise_symbol=?, phone_prefix=?, enabled=?, sort_order=? WHERE code=?");
foreach ($pays as $p) {
    $ins->execute($p);
    if ($ins->rowCount() > 0) $inserted++;
    else {
        $upd->execute([$p[1],$p[2],$p[3],$p[4],$p[5],$p[6],$p[7],$p[0]]);
        $updated++;
    }
}
$total = $pdo->query("SELECT COUNT(*) FROM countries_config WHERE enabled=1")->fetchColumn();
echo json_encode(['ok'=>true, 'inserted'=>$inserted, 'updated'=>$updated, 'total_enabled'=>(int)$total], JSON_UNESCAPED_UNICODE);
