<?php

namespace Draxter\Aichat;

class CurlHelper
{
    /**
     * @param resource|\CurlHandle $ch
     */
    public static function applySslOptions($ch): void
    {
        if (!Settings::sslVerify()) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            return;
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $cainfo = ini_get('curl.cainfo') ?: ini_get('openssl.cafile');
        if (is_string($cainfo) && $cainfo !== '' && is_file($cainfo)) {
            curl_setopt($ch, CURLOPT_CAINFO, $cainfo);
        }
    }

    /**
     * @param resource|\CurlHandle|null $ch
     */
    public static function close($ch): void
    {
        if ($ch === null) {
            return;
        }
        if (PHP_VERSION_ID < 80000 && is_resource($ch)) {
            curl_close($ch);
            return;
        }
        unset($ch);
    }
}
