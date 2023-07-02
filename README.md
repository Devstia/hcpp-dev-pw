# hcpp-cg-pws
Code Garden Personal Web Server plugin for HestiaCP enables Code Garden features on the desktop app.

&nbsp;
 > :warning: !!! Note: this repo is in progress; when completed, a release will appear in the release tab.
 
&nbsp;
## Installation
HCPP-CG-PWS requires an Ubuntu or Debian based installation of [Hestia Control Panel](https://hestiacp.com) in addition to an installation of [HestiaCP-Pluginable](https://github.com/virtuosoft-dev/hestiacp-pluginable) to function; please ensure that you have first installed pluginable on your Hestia Control Panel before proceeding. Switch to a root user and simply clone this project to the /usr/local/hestia/plugins folder. It should appear as a subfolder with the name `cg-pws`, i.e. `/usr/local/hestia/plugins/cg-pws`.

First, switch to root user:
```
sudo -s
```

Then simply clone the repo to your plugins folder, with the name `cg-pws`:

```
cd /usr/local/hestia/plugins
git clone https://github.com/virtuosoft-dev/hcpp-cg-pws cg-pws
```

Note: It is important that the destination plugin folder name is `cg-pws`.


Be sure to logout and login again to your Hestia Control Panel as the admin user or, as admin, visit Server (gear icon) -> Configure -> Plugins -> Save; the plugin will immediately start installing cg-pws depedencies in the background. 

A notification will appear under the admin user account indicating *”CG-PWS plugin has finished installing”* when complete. This may take awhile before the options appear in Hestia. You can force manual installation via:

```
cd /usr/local/hestia/plugins/cg-pws
./install
touch “/usr/local/hestia/data/hcpp/installed/cg-pws”
```

&nbsp;
## About CG-PWS
This plugin will modify Hestia and the included development stack to accommodate local development. This includes, but is not limited to:

* Creating a root self-signed certificate for automated SSL website development. 
* Furnishing cutting edge bug fixes and functionality geared towards local development with Code Garden Personal Web Server. 
   
<br>

## Support the creator
You can help this author’s open source development endeavors by donating any amount to Stephen J. Carnam @ Virtuosoft. Your donation, no matter how large or small helps pay for essential time and resources to create MIT and GPL licensed projects that you and the world can benefit from. Click the link below to donate today :)
<div>
         

[<kbd> <br> Donate to this Project <br> </kbd>][KBD]


</div>


<!—————————>

[KBD]: https://virtuosoft.com/donate

https://virtuosoft.com/donate