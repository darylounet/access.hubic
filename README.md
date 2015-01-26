# access.hubic
hubiC access plugin for Pydio (formerly AjaXplorer)

The HubiC plugin is highly experimental. It shall be used to access a hubiC account via their Auth API + Swift.

Inspired by access.dropbox and access.swift plugins by @cdujeu . 

## How to get it work
I assume that you already have Pydio installed.

To use it, you must first <a href="https://hubic.com" target="_blank">register an HubiC account</a> You can use my recommending key : `SXEXHU` to get additional space for free.

Then, go to the <a href="https://hubic.com/home/browser/developers/" target="_blank">developers settings page</a> and add an application.
Give a custom name and give your own Pydio URL as redirect domain.

It will give you `Client ID` and `Client Secret` settings.

In your Pydio install, go to the `plugins` directory and `git clone` this repository.

Then `cd access.hubic` and `git clone https://github.com/stackforge/openstack-sdk-php.git`.

`cd openstack-sdk-php/` and `composer.phar install`

Login on your Pydio, activate the plugin. Add a repository and enter the previous `Client ID` and `Client Secret` settings.

Try to enter in your newly created repository, it will asks you to click on a link in order to retrieve your tokens.
Your are redirected on the hubiC website, enter your credentials and follow the steps.

You're done !

