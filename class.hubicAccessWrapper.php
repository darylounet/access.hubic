<?php
/*
 * Copyright 2015 Cyril Aknine
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <https://github.com/darylounet/access.hubic/>.
 *
 */
defined('AJXP_EXEC') or die( 'Access not allowed');

require_once(AJXP_INSTALL_PATH .'/plugins/access.swift/class.swiftAccessWrapper.php');
require_once(AJXP_INSTALL_PATH .'/plugins/access.hubic/HubicBootStrap.php');

/**
 * AJXP_Plugin to access a HubiC account
 * @package AjaXplorer_Plugins
 * @subpackage Access
 */
class hubicAccessWrapper extends swiftAccessWrapper
{
    public static $lastException;
    private static $cloudContext;

    /**
     * Initialize the stream from the given path.
     * Concretely, transform ajxp.swiftfs:// into swiftfs://
     *
     * @param string $path
     * @return mixed Real path or -1 if currentListing contains the listing : original path converted to real path
     */
    protected static function initPath($path, $streamType, $storeOpenContext = false, $skipZip = false)
    {
        $url = parse_url($path);
        if (self::$cloudContext == null) {
            self::$cloudContext = stream_context_create(
                array('swiftfs' =>
                    array(
                        'token' => $_SESSION['PROP_HUBIC_account/credentials']['token'],
                        'swift_endpoint' => $_SESSION['PROP_HUBIC_account/credentials']['endpoint']
                    )
                )
            );
        }

        // Base container on HubiC is "default"
        return 'swiftfs://default' . str_replace('//', '/', $url['path']);
    }

    /**
     * Opens the stream
     * Diff with parent class : do not "securePath", as it removes double slash
     *
     * @param String $path Maybe in the form "ajxp.fs://repositoryId/pathToFile"
     * @param String $mode
     * @param unknown_type $options
     * @param unknown_type $opened_path
     * @return unknown
     */
    public function stream_open($path, $mode, $options, &$context)
    {
        try {
            $this->realPath = $this->initPath($path, "file");
        } catch (Exception $e) {
            AJXP_Logger::error(__CLASS__,"stream_open", "Error while opening stream $path");
            return false;
        }
        if ($this->realPath == -1) {
            $this->fp = -1;
            return true;
        } else {
            $this->fp = fopen($this->realPath, $mode, $options, self::$cloudContext);
            return ($this->fp !== false);
        }
    }

    /**
     * Opens a handle to the dir
     * Fix PEAR by being sure it ends up with "/", to avoid
     * adding the current dir to the children list.
     *
     * @param unknown_type $path
     * @param unknown_type $options
     * @return unknown
     */
    public function dir_opendir($path , $options )
    {
        $this->realPath = $this->initPath($path, "dir", true);
        if ($this->realPath[strlen($this->realPath)-1] != "/") {
            $this->realPath.="/";
        }
        if (is_string($this->realPath)) {
            $this->dH = opendir($this->realPath, self::$cloudContext);
        } elseif ($this->realPath == -1) {
            $this->dH = -1;
        }
        return $this->dH !== false;
    }

    public function mkdir($path, $mode, $options)
    {
        $this->realPath = $this->initPath($path, "dir", true);
        file_put_contents($this->realPath."/.marker", "tmpdata");
        return true;
    }

    // DUPBLICATE STATIC FUNCTIONS TO BE SURE
    // NOT TO MESS WITH self:: CALLS

    public static function removeTmpFile($tmpDir, $tmpFile)
    {
        if(is_file($tmpFile)) unlink($tmpFile, self::$cloudContext);
        if(is_dir($tmpDir)) rmdir($tmpDir, self::$cloudContext);
    }

    protected static function closeWrapper()
    {
        if (self::$crtZip != null) {
            self::$crtZip = null;
            self::$currentListing  = null;
            self::$currentListingKeys = null;
            self::$currentListingIndex = null;
            self::$currentFileKey = null;
        }
    }

    public static function getRealFSReference($path, $persistent = false)
    {
        $tmpFile = AJXP_Utils::getAjxpTmpDir()."/".md5(time()).".".pathinfo($path, PATHINFO_EXTENSION);
           $tmpHandle = fopen($tmpFile, "wb", null, self::$cloudContext);
           self::copyFileInStream($path, $tmpHandle);
           fclose($tmpHandle);
           if (!$persistent) {
               register_shutdown_function(array("AJXP_Utils", "silentUnlink"), $tmpFile);
           }
           return $tmpFile;
    }


    public static function isRemote()
    {
        return true;
    }

    public static function copyFileInStream($path, $stream)
    {
        $fp = fopen($path, "r", null, self::$cloudContext);
        while (!feof($fp)) {
            $data = fread($fp, 4096);
            fwrite($stream, $data, strlen($data));
        }
        fclose($fp);
    }

    public static function changeMode($path, $chmodValue)
    {
        // DO NOTHING!
        //$realPath = self::initPath($path, "file");
        //chmod($realPath, $chmodValue);
    }
}
