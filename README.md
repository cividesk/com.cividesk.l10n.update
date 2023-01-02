# Localization Update

Automated download of translations files for CiviCRM core and extensions.

This extension optimizes the downloads in terms of frequency and needed files for your current setup.

There are no setup screens or additional menu items for this extension. Just access the localization settings or
extension management screen and it will work it's magic.

This extension was originated during the 2014 CiviCON Sprint in Lake Tahoe. Attending a Sprint is a great way to support
our community, meet wonderful people, and have loads of fun even if you are not a programmer or implementer.

In "single language mode" this extension allows you to select multiple UI languages even if they are not installed.
Once selected the extension will automatically download them.

## Requirements

* The hosting server must have php5-curl installed.
* The civicrm/l10n directory must be writable by the web server,
  as well as the l10n directory of each extension installed.
These requirements are normally fulfilled in a standard CiviCRM installation.

## Installation

This extension cannot be installed using the CiviCRM extension manager. The instructions [here](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/#installing-a-new-extension) gives the general instructions, but since this repo has no released package, so you should

- Download the complete repo as a zip
- Unzip that into the extension directory, by default called `ext` in the media directory or similar, but check Administer->System Settings->Directories
- Re-load the Manage Extension page to see the package as an installable option

## Usage

Once installed, this extension will automatically download translation files for CiviCRM core and extensions when you
access either the localization settings or extension management screens in CiviCRM administration. The localization
settings screen will also display all possible languages you can enable in CiviCRM.

You can manually trigger the update using the API3: `L10nupdate.fetch`

## Support

The latest version of this extension can be found at:
https://github.com/cividesk/com.cividesk.l10n.update

The bug tracker is located at:
https://github.com/cividesk/com.cividesk.l10n.update/issues

## Copyright

Copyright (C) 2014 IT Bliss LLC, http://cividesk.com/

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published
by the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
