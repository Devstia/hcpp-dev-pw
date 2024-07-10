# hcpp-dev-pw
Devstia Preview plugin for HestiaCP enables Devstia Preview features on the desktop app.

&nbsp;
 > :warning: !!! Note: NOT for live or production servers; for "localhost" development only! This repo contains code for ease-of-development only and may pose security risks on untested servers. 
 
&nbsp;
## Installation
HCPP-DEV-PW requires an Ubuntu or Debian based installation of [Hestia Control Panel](https://hestiacp.com) in addition to an installation of [HestiaCP-Pluginable](https://github.com/virtuosoft-dev/hestiacp-pluginable) to function; please ensure that you have first installed pluginable on your Hestia Control Panel before proceeding. Switch to a root user and simply clone this project to the /usr/local/hestia/plugins folder. It should appear as a subfolder with the name `dev-pw`, i.e. `/usr/local/hestia/plugins/dev-pw`.

First, switch to root user:
```
sudo -s
```

Then simply clone the repo to your plugins folder, with the name `dev-pw`:

```
cd /usr/local/hestia/plugins
git clone https://github.com/virtuosoft-dev/hcpp-dev-pw dev-pw
```

Note: It is important that the destination plugin folder name is `dev-pw`.


Be sure to logout and login again to your Hestia Control Panel as the admin user or, as admin, visit Server (gear icon) -> Configure -> Plugins -> Save; the plugin will immediately start installing dev-pw depedencies in the background. 

A notification will appear under the admin user account indicating *”DEV-PW plugin has finished installing”* when complete. This may take awhile before the options appear in Hestia. You can force manual installation via:

```
cd /usr/local/hestia/plugins/dev-pw
./install
touch "/usr/local/hestia/data/hcpp/installed/dev-pw"
```

&nbsp;
## About DEV-PW
This plugin will modify Hestia and the included development stack to accommodate local development. This includes, but is not limited to:

* Creating a root self-signed certificate for automated SSL website development. 
* Furnishing cutting edge bug fixes and functionality geared towards local development with the Devstia Preview application. 
   
<br>

## Support the creator
You can help this author's open source development endeavors by donating any amount to Stephen J. Carnam @ Virtuosoft. Your donation, no matter how large or small helps pay for essential time and resources to create MIT and GPL licensed projects that you and the world can benefit from. Click the link below to donate today :)
<div>
         

[<kbd> <br> Donate to this Project <br> </kbd>][KBD]


</div>


<!---------------------------------------------------------------------------->

[KBD]: https://virtuosoft.com/donate

https://virtuosoft.com/donate
