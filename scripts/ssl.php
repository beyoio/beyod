<?php

$info = array(
    "countryName" => "CN",
    "stateOrProvinceName" => "Guangzhou",
    "localityName" => "Zhuhai",
    "organizationName" => "beyod.net",
    "organizationalUnitName" => "Project Team",
    "commonName" => "localhost:9723", //网站的域名，可以使用*作通配符
    "emailAddress" => "zhangxu@beyod.net"
);


$privateKey = openssl_pkey_new(); //生成私钥

$csr = openssl_csr_new($info, $privateKey); //根据证书信息,私钥,生成证书签名请求

$certificate = openssl_csr_sign($csr, null, $privateKey, 365);//根据签名请求/私钥,生成过一个365天过期的证书资源

$passphrase='beyodpass'; //证书密码
$pem = [];
openssl_x509_export($certificate, $pem[0]); //以字符串格式导出证书
openssl_pkey_export($privateKey, $pem[1], $passphrase); //导出密钥字符串
$pem = implode($pem);

//保存证书文件
$pemfile = './server.pem';
file_put_contents($pemfile, $pem);
