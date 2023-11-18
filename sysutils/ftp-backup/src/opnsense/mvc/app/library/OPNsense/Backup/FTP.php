<?php

/*
 * Copyright (C) 2021 Manuel Hofmann
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Backup;

use OPNsense\Core\Config;
use OPNsense\Backup\FTPSettings;

/**
 * Class FTP backup
 * @package OPNsense\Backup
 */
class FTP extends Base implements IBackupProvider
{
    /**
     * get required (user interface) fields for backup connector
     * @return array configuration fields, types and description
     */
    public function getConfigurationFields()
    {
        $fields = array(
            array(
                "name" => "enabled",
                "type" => "checkbox",
                "label" => gettext("Enable"),
                "value" => null
            ),
            array(
                "name" => "url",
                "type" => "text",
                "label" => gettext("URL"),
                "help" => gettext("The URL to server with trailing slash. For example: ftp://ftp.example.com/ or ftps://ftp.example.com/folder/"),
                "value" => null
            ),
            array(
                "name" => "port",
                "type" => "text",
                "label" => gettext("Port"),
                "help" => gettext("The port you use for logging into your FTP server"),
                "value" => null
            ),
            array(
                "name" => "user",
                "type" => "text",
                "label" => gettext("User Name"),
                "help" => gettext("The name you use for logging into your FTP server"),
                "value" => null
            ),
            array(
                "name" => "password",
                "type" => "password",
                "label" => gettext("Password"),
                "help" => gettext("The password for your FTP user"),
                "value" => null
            ),
            array(
                "name" => "password_encryption",
                "type" => "password",
                "label" => gettext("Encryption Password (Optional)"),
                "help" => gettext("A password to encrypt your configuration"),
                "value" => null
            ),
            array(
                "name" => "passive",
                "type" => "checkbox",
                "label" => gettext("Passive mode"),
                "help" => gettext("Active to enable passive mode"),
                "value" => null
            ),
            array(
                "name" => "ssl",
                "type" => "checkbox",
                "label" => gettext("TLS/SSL"),
                "help" => gettext("Active to enable TLS/SSL"),
                "value" => null
            )

        );
        $ftp = new FTPSettings();
        foreach ($fields as &$field) {
            $field['value'] = (string)$ftp->getNodeByReference($field['name']);
        }
        return $fields;
    }

    /**
     * backup provider name
     * @return string user friendly name
     */
    public function getName()
    {
        return gettext("FTP");
    }

    /**
     * validate and set configuration
     * @param array $conf configuration array
     * @return array of validation errors when not saved
     * @throws \OPNsense\Base\ModelException
     * @throws \ReflectionException
     */
    public function setConfiguration($conf)
    {
        $ftp = new FTPSettings();
        $this->setModelProperties($ftp, $conf);
        $validation_messages = $this->validateModel($ftp);
        if (empty($validation_messages)) {
            $ftp->serializeToConfig();
            Config::getInstance()->save();
        }
        return $validation_messages;
    }

    /**
     * perform backup
     * @return array filelist
     * @throws \OPNsense\Base\ModelException
     * @throws \ReflectionException
     * @throws \Exception
     */
    public function backup()
    {
        $cnf = Config::getInstance();
        $ftp = new FTPSettings();

        if ($cnf->isValid() && !empty((string)$ftp->enabled)) {
            $config = $cnf->object();

            $url = (string)$ftp->url;
            $user = (string)$ftp->user;
            $password = (string)$ftp->password;
            $crypto_password = (string)$ftp->password_encryption;
            $port = (string)$ftp->port;
            $hostname = $config->system->hostname . '.' . $config->system->domain;

            $configname = 'config-' . $hostname . '-' .  date('Y-m-d_H_i_s') . '.xml';

            $confdata = file_get_contents('/conf/config.xml');
            if (!empty($crypto_password)) {
                $confdata = $this->encrypt($confdata, $crypto_password);
            }

            syslog(LOG_DEBUG, 'starting backup via ftp');

            try {

                $this->uploadFileContent($configname, $confdata);

                return $this->listFiles($url, $user, $password, $port);

            } catch (Exception $error) {
                syslog(LOG_ERR, $error);
            }

        }

    }

    /**
     * dir listing
     * @param string $url remote location
     * @param string $username username
     * @param string $password password to use
     * @return array
     * @throws \Exception when listing files fails
     */
    public function listFiles($url, $username, $password, $port)
    {
        $curl = $this->getCurlHandle($filename);

        $curlOptions = [
            CURLOPT_DIRLISTONLY => 1,
        ];

        $this->setCurlOptions($curl, $curlOptions);

        $response = $this->curlExecute($curl);

        $files = explode("\n", $response);

        // e.g. filter folders ".." and "."
        return preg_grep('"config-.*\.xml"', $files);
    }

    /**
     * upload file
     * @param string $url remote location
     * @param string $username remote user
     * @param string $password password to use
     * @param string $filename filename to use
     * @param string $file_content contents to save
     * @throws \Exception when upload fails
     */
    public function uploadFileContent($filename, $file_content)
    {
        $curl = $this->getCurlHandle($filename);

        $infile = tmpfile();
        fwrite($infile, $file_content);
        fseek($infile, 0);

        $curlOptions = [
            CURLOPT_UPLOAD => 1,
            CURLOPT_INFILE => $infile,
            CURLOPT_INFILESIZE => strlen($file_content),
        ];

        $this->setCurlOptions($curl, $curlOptions);

        $this->curlExecute($curl);
    }

    /**
     * Is this provider enabled
     * @return boolean enabled status
     * @throws \OPNsense\Base\ModelException
     * @throws \ReflectionException
     */
    public function isEnabled()
    {
        $ftp = new FTPSettings();
        return (string)$ftp->enabled === "1";
    }

    private function getCurlHandle($path = '')
    {

        $ftp = new FTPSettings();

        if(!$ftp->url) {
            throw new \Exception('URL is mandatory');
        }

        if(!$ftp->port) {
            throw new \Exception('FTP Port is mandatory');
        }

        $handle = curl_init();

        if(!$handle) {
            throw new \Exception('Could not initialize cURL handle');
        }

        $url = (string)$ftp->url;
        $user = (string)$ftp->user;
        $port = (string)$ftp->port;

        $curlOptions = [
            CURLOPT_URL => $url . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERPWD => $user,
            CURLOPT_PORT => $port,
        ];

        // check ftp passive mode
        if((string)$ftp->passive !== "1") {
            syslog(LOG_DEBUG, 'ftp-backup: passive mode disabled');
            $curlOptions[CURLOPT_FTPPORT] = '-';
        }

        if($ftp->password && strlen($ftp->password) >0) {
            // append password if set
            $curlOptions[CURLOPT_USERPWD] .= ":" . (string)$ftp->password;
        }

        if((string)$ftp->ssl === "1") {
            syslog(LOG_DEBUG, 'ftp-backup: tls/ssl enabled');
            $curlOptions[CURLOPT_FTPSSLAUTH] = CURLFTPAUTH_TLS; // let cURL choose the FTP authentication method (either SSL or TLS)
        }

        $this->setCurlOptions($handle, $curlOptions);

        return $handle;
    }

    private function setCurlOptions($handle, $options)
    {
        if(!curl_setopt_array($handle, $options)) {
            throw new \Exception('Could not set cURL options');
        };
    }

    private function curlExecute($handle)
    {

        $response = curl_exec($handle);

        $error = curl_error($handle);

        curl_close($handle);

        if($error) {
            throw new \Exception($error);
        }

        return $response;
    }
}
