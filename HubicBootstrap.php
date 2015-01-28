<?php
namespace OpenStack;

require_once(dirname(__FILE__) .'/openstack-sdk-php/src/OpenStack/Bootstrap.php');
require_once(dirname(__FILE__) .'/HubicStreamWrapperFS.php');

use OpenStack\ObjectStore\v1\Resource\StreamWrapper;
use OpenStack\ObjectStore\v1\Resource\StreamWrapperFS;

class HubicBootstrap extends Bootstrap
{
    public static function useStreamWrappers()
    {
        self::enableStreamWrapper(
            StreamWrapper::DEFAULT_SCHEME,
            'OpenStack\ObjectStore\v1\Resource\StreamWrapper'
        );
        self::enableStreamWrapper(
            StreamWrapperFS::DEFAULT_SCHEME,
            'OpenStack\ObjectStore\v1\Resource\HubicStreamWrapperFS'
        );
    }

    private static function enableStreamWrapper($scheme, $class)
    {
        if (in_array($scheme, stream_get_wrappers())) {
            stream_wrapper_unregister($scheme);
        }

        stream_wrapper_register($scheme, $class);
    }
}
