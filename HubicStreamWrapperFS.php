<?php
namespace OpenStack\ObjectStore\v1\Resource;

require_once(dirname(__FILE__) .'/openstack-sdk-php/src/OpenStack/ObjectStore/v1/Resource/StreamWrapper.php');
require_once(dirname(__FILE__) .'/openstack-sdk-php/src/OpenStack/ObjectStore/v1/Resource/StreamWrapperFS.php');

use \OpenStack\Bootstrap;
use OpenStack\Common\Transport\Exception\ResourceNotFoundException;
use \OpenStack\ObjectStore\v1\ObjectStorage;
use OpenStack\Common\Exception;

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
}
