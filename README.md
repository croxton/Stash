#Stash

* Author: [Mark Croxton](http://hallmark-design.co.uk/)

## Version 1.0.0

* Requires: ExpressionEngine 2

## Description

Stash allows you to stash text and snippets of code for reuse throughout your templates. Variables can be set dynamically from the $_GET or $_POST superglobals and can optionally be cached in the database for persistence across pages.

Stash variables that you create are available to templates embedded below the level at which you are using the tag, or later in the parse order of the current template.

Stash is inspired by John D Wells' article on [template partials](http://johndwells.com/blog/homegrown-plugin-to-create-template-partials-for-expressionengine), and Rob Sanchez's [Dynamo](https://github.com/rsanchez/dynamo) module. 

## Installation

1. Copy the stash folder to ./system/expressionengine/third_party/
2. In the CP, navigate to Add-ons > Modules and click the 'Install' link for the Stash module

## {exp:stash:set} tag pair

### name = [string]
The name of your variable (required). 
This should be a unique name. Use underscores for spaces and use only alphanumeric characters.
Note: if you use the same variable twice the second one will overwrite the first.

### type = ['variable'|'snippet']
The type of variable to create (optional, default is 'variable').
A 'variable' is stored in the session, and must be retrieved using {exp:stash:get name=""}.
A 'snippet' works just like snippets in ExpressionEngine, and can be used directly as a tag {my_variable}
If using snippets, please be careful to namespace them so as not to overwrite any existing EE globals. 

### cache = ['yes'|'no']
Do you want to store the variable in the database so that it persists across page loads? (optional, default is 'no')
Note that you should never cache any sensitive user data.

### refresh = [int]
The number of minutes to store the variable (optional, default is 1440 - or one day)

### output = ['yes'|'no']
Do you want to output the content inside the tag pair (optional, default is 'no')

### scope = ['user'|'site']
When cache ="yes", determines if the variable is locally scoped to the User's session, or globally (set for everyone who visits the site) (optional, default is 'user').

#### scope = "user"
A 'user' variable is linked to the users session id. Only they will see it. Use for pagination, search queries, or chunks of personalised content that you need to cache across multiple pages. 

#### scope = "site"
A 'global' variable is set only once until it expires, and is accessible to ALL site visitors. Use in combination with [Switchee](https://github.com/croxton/Switchee) for caching and reusing rendered content throughout your site.

### Example usage:

	{exp:channel:entries limit="1" disable="member_data|pagination|categories"}	
		{exp:stash:set name="title" type="snippet"}{title}{/exp:stash:set}
	{/exp:channel:entries}
	
### Advanced usage:

	{!-- get the variable if it exists, but don't output it --}
	{exp:stash:get name="test" scope="site" output="no"}

	{!-- caching logic. Switchee will check for Stash vars if you prefix with 'stash:' --}
	{exp:switchee variable="stash:test" parse="inward"}	
	
		{case value=""}

			This is un-cached, so let's cache it for 5 minutes.

			{exp:channel:entries entry_id="1" limit="1"}
				{exp:stash:set name="test" scope="site" save="yes" output="yes" refresh="5"}	
					{title}
				{/exp:stash:set}
			{/exp:channel:entries}
		{/case}

		{case default="Yes"}
		
			This is cached. Cool eh?

			{exp:stash:get name="test" scope="site"}	
		{/case}
	
	{/exp:switchee}


## {exp:stash:append} tag pair	
Works the same as {exp:stash:set}, except the value is appended to an existing variable.

## {exp:stash:prepend} tag pair	
Works the same as {exp:stash:set}, except the value is prepended to an existing variable.
	
## {exp:stash:get}

### name = [string]
The name of your variable (required)

### type = ['variable'|'snippet']
The type of variable to retrieve (optional, default is 'variable').

### dynamic = ['yes'|'no']
Look in the $_POST and $_GET superglobals array for the variable (optional, default is 'no').

### cache = ['yes'|'no']
When using dynamic="yes", do you want to store the dynamic value in the database so that it persists across page loads? (optional, default is 'no')

### refresh = [int]
When using dynamic="yes", the number of minutes to store the variable (optional, default is 1440 - or one day)

### default = [string]
Default value to return if variable is not set or empty (optional, default is an empty string)

### output = ['yes'|'no']
Do you want to output the variable or just get the variable quietly in the background? (optional, default is 'yes')

### scope = ['user'|'site']
Is the variable locally scoped to the User's session, or global (set for everyone who visits the site) (optional, default is 'user').
Note: use the same scope that you used when you set the variable.

### strip_tags = ['yes'|'no']
Strip HTML tags from the returned variable? (optional, default is 'no').

### strip_curly_braces = ['yes'|'no']
Strip HTML tags from the returned variable? (optional, default is 'no').

### Example usage
	{exp:stash:get name="title"}
	
### Advanced usage	
	Let's say you have a search form and you need to register a form field value and persist it across page views:
	
	{exp:stash:get name="my_form_field_name" type="snippet" dynamic="yes" cache="yes" refresh="30"}
	
	In an embedded template we have a {exp:channel:entries} tag producing a paginated listing of entries:
	{exp:channel:entries search:custom_field="{my_form_field_name}" disable="member_data|pagination|categories"}
		...
	{/exp:channel:entries}
	
## {exp:stash:flush_cache}
Add this tag to a page to clear all cached variables. You have to be logged in a Super Admin to clear the cache.
	
## {exp:stash:bundle} tag pair	
Bundle up a series of variables into one.

### name = [string]
The name of your bundle (required)

### unique = ['yes'|'no']
Do you only want to allow one entry per bundle? (optional, default is 'no')

### Example usage
	{exp:stash:bundle name="contact_form" unique="no"}
			{stash:contact_name}Your name{/stash:contact_name}
			{stash:contact_email}mcroxton@hallmark-design.co.uk{/stash:contact_email}
		{/exp:stash:bundle}
		
The Bundles feature is under development, so not especially useful just yet.
