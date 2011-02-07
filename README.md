# Campaign Monitor

The Campaign Monitor extension allows you to add subscribers to your Campaign Monitor
lists via Symphony events.

- Version: 0.9
- Date: 7th February 2011
- Requirements: Symphony 2.0.6 or newer, <http://github.com/symphonycms/symphony-2/>
- Author: Brendan Abbott [brendan@bloodbone.ws], Rowan Lewis [me@rowanlewis.com]
- GitHub Repository: <http://github.com/brendo/campaign_monitor>

## INSTALLATION

1. Upload the 'campaign_monitor' folder in this archive to your Symphony 'extensions' folder.
2. Enable it by selecting the "Campaign Monitor", choose Enable from the with-selected menu, then click Apply.
3. You can now add the "Campaign Monitor" filter to your Events.

## USAGE

1. Go to your Campaign Monitor account and get your API Key (Account Settings). Add this to the preferences page.
2. Create an event and attach the Campaign Monitor filter to the Event
3. In your Campaign Monitor account, find the list that you want to add Subscribers to and find it's List ID (change name/type)
4. Create your form in the XSLT and at minimum add the following three input fields

	<input name="campaignmonitor[list]" value="{$your-list-id}" type="hidden" />
	<input name="campaignmonitor[field][Name]" value="$field-name" type="hidden" />
	<input name="campaignmonitor[field][Email]" value="$field-email" type="hidden" />

The `$field-name` syntax will get the value of the <input name='fields[name]' /> when posting to C+S.

You can add custom fields exactly the same way:

	<input name="campaignmonitor[field][Name]" value="$field-name" type="hidden" />
	<input name="campaignmonitor[field][CustomFieldHandle]" value="$field-custom-field" type="hidden" />


## CHANGE LOG

*0.9* (7th February 2011)

- Initial Public Release