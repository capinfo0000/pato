<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| テストから外部サービスの実資格情報を隔離する
|--------------------------------------------------------------------------
|
| `.env` やシェルに実キーが入ったままテストを流すと、Fake ではなく実アダプタが
| 選ばれ、**テストが本物の Stripe に課金しに行く**（レート制限のテストは購入を
| 6回叩く）。気づいたときには請求が立っている類の事故なので、入口で断つ。
|
| phpunit.xml の <env force="true"> だけでは足りない: PHPUnit が消すのは
| getenv() と $_ENV で、$_SERVER は残る。Laravel の env() は $_SERVER を先に
| 見るため、シェルで export された値がそのまま通ってしまう。3つとも消すこと。
|
| 隔離が効いていることは NoRealCredentialsInTestsTest が検証する。
*/
$isolated = [
    'STRIPE_PUBLISHABLE_KEY',
    'STRIPE_SECRET',
    'STRIPE_WEBHOOK_SECRET',
    'EKYC_BASE_URL',
    'EKYC_API_KEY',
    'VAPID_PUBLIC_KEY',
    'VAPID_PRIVATE_KEY',
];

foreach ($isolated as $name) {
    putenv($name.'=');
    $_ENV[$name] = '';
    $_SERVER[$name] = '';
}
