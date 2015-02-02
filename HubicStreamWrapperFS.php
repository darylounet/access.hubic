<?php
namespace OpenStack\ObjectStore\v1\Resource;

require_once(dirname(__FILE__) .'/openstack-sdk-php/vendor/autoload.php');

class HubicStreamWrapperFS extends StreamWrapperFS
{
    protected function writeRemote()
    {
        $contentType = $this->cxt('content_type');
        if (!empty($contentType)) {
            $this->obj->setContentType($contentType);
        }

        // Skip debug streams.
        if ($this->isNeverDirty) {
            return;
        }

        // Stream is dirty and needs a write.
        if ($this->isDirty) {
            if ($contentType === null) {
                // TODO: try to retrieve MIME type directly from $this->objStream and \finfo::buffer
                if (!empty($_FILES)) {
                    foreach ($_FILES as $file) {
                        if ($file['name'] === basename($this->obj->name())) {
                            // don't trust $file['type'] as it can be spoofed
                            $finfo = new \finfo(FILEINFO_MIME);
                            $mime = $finfo->file($file['tmp_name'], FILEINFO_MIME_TYPE);
                            if ($mime) {
                                $this->obj->setContentType($mime);
                            }
                        }
                    }
                }
            }
            $position = ftell($this->objStream);

            rewind($this->objStream);
            $this->container->save($this->obj, $this->objStream);

            fseek($this->objStream, SEEK_SET, $position);

        }
        $this->isDirty = false;
    }

    protected function initializeObjectStorage()
    {
        $token = $this->cxt('token', $_SESSION['PROP_HUBIC_account/credentials']['token']);
        $endpoint = $this->cxt('swift_endpoint', $_SESSION['PROP_HUBIC_account/credentials']['endpoint']);
        $client = $this->cxt('transport_client', \OpenStack\Common\Transport\Guzzle\GuzzleAdapter::create());

        if (!empty($token) && !empty($endpoint)) {
            $this->store = new \OpenStack\ObjectStore\v1\ObjectStorage($token, $endpoint, $client);
        } else {
            throw new \OpenStack\Common\Exception('Missing Token or Endpoint.');
        }

        return !empty($this->store);
    }

    public function url_stat($path, $flags)
    {
        $url = $this->parseUrl($path);
        try {
            $this->initializeObjectStorage();
            $container = $this->store->container($url['host']);
            $object = $container->objectsWithPrefix($url['path'], '/', 1)[0];
            if ($object === null) {
                return false;
            }
            if ($object->contentType() === 'application/directory') {
                return $this->fakeStat(true, $object);
            }
            $stat = parent::url_stat($path, $flags);

            // If the file stat setup returned anything return it.
            if ($stat) {
                return $stat;
            }

            return false;
        } catch (\OpenStack\Common\Exception $e) {
            return false;
        }
    }

    protected function fakeStat($dir = false, Object $object = null)
    {
        $stat = parent::fakeStat($dir);

        if ($object !== null) {
            $stat['atime'] = $object->lastModified();
            $stat['mtime'] = $object->lastModified();
            $stat['ctime'] = $object->lastModified();
        }

        return $stat;
    }
}
