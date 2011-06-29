#Stash

* Author: [Mark Croxton](http://hallmark-design.co.uk/)

## Version 1.1.3

* Requires: ExpressionEngine 2

## Description

Stash allows you to stash text and snippets of code for reuse throughout your templates. Variables can be set dynamically from the $_GET or $_POST superglobals and can optionally be cached in the database for persistence across pages.

Stash variables that you create are available to templates embedded below the level at which you are using the tag, or later in the parse order of the current template.

Stash is inspired by John D Wells' article on [template partials](http://johndwells.com/blog/homegrown-plugin-to-create-template-partials-for-expressionengine), and Rob Sanchez's [Dynamo](https://github.com/rsanchez/dynamo) module. 

## Key features
* Set, append, prepend and get variables
* Use regular expressions to filter variables that you stash
* Register variables from the $_POST, $_GET and uri segment arrays
* Save variables to the database for persistent storage across page loads
* Set variable scope (user or global)
* Fully parse tags contained within a Stash tag pair, so Stash saves the rendered output
* Use contexts to namespace groups of variables and help organise your code
* Advanced uses: partial page caching, form field persistence, template partials/viewModel pattern implementation 

## Installation

1. Copy the stash folder to ./system/expressionengine/third_party/
2. In the CP, navigate to Add-ons > Modules and click the 'Install' link for the Stash module

## {exp:stash:set} tag pair

### name = [string]
The name of your variable (optional). 
This should be a unique name. Use underscores for spaces and use only alphanumeric characters.
Note: if you use the same variable twice the second one will overwrite the first.

### type = ['variable'|'snippet']
The type of variable to create (optional, default is 'variable').
A 'variable' is stored in the session, and must be retrieved using {exp:stash:get name=""}.
A 'snippet' works just like snippets in ExpressionEngine, and can be used directly as a tag {my_variable}
If using snippets, please be careful to namespace them so as not to overwrite any existing EE globals. 

### save = ['yes'|'no']
Do you want to store the variable in the database so that it persists across page loads? (optional, default is 'no')
Note that you should never cache any sensitive user data.

### refresh = [int]
The number of minutes to store the variable (optional, default is 1440 - or one day)

### output = ['yes'|'no']
Do you want to output the content inside the tag pair (optional, default is 'no')

### parse_tags = ['yes'|'no']
Do you want to parse any tags (modules or plugins) contained inside the stash tag pair (optional, default is 'no')

### match = [#regex#]
Variable will only be set if the content matches the supplied regular expression (optional)

### against = [string]
String to match against if using the match= parameter (optional).
By default, the variable content is used.

### context = [string]
If you want to assign the variable to a context, add it here. 
Tip: Use '@' to refer to the current context:

	{exp:stash:set name="title" context="@"}
	...
	{exp:stash:set}


### scope = ['user'|'site']
When save ="yes", determines if the variable is locally scoped to the User's session, or globally (set for everyone who visits the site) (optional, default is 'user').

#### scope = "user"
A 'user' variable is linked to the users session id. Only they will see it. Use for pagination, search queries, or chunks of personalised content that you need to cache across multiple pages. 

#### scope = "site"
A 'global' variable is set only once until it expires, and is accessible to ALL site visitors. Use in combination with [Switchee](https://github.com/croxton/Switchee) for caching and reusing rendered content throughout your site.

### Example usage:

	{exp:channel:entries limit="1" disable="member_data|pagination|categories"}	
		{exp:stash:set name="title" type="snippet"}{title}{/exp:stash:set}
	{/exp:channel:entries}
	
### Advanced usage 1

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

### Advanced usage 2
{exp:stash:set} called WITHOUT a name="" parameter can be used to set multiple variables wrapped by tag pairs {stash:variable1}....{/stash:variable1} etc. These tag pairs can even be nested. 

In this example we want to ensure that the inner {exp:channel:entries} tag is parsed so we set parse_tags="yes". Then we want to capture the the unordered list and the total count of entries so we can use them elsewhere in the same template:

	{exp:stash:set parse_tags="yes"}
		{stash:content}
		<ul>
		{exp:channel:entries channel="blog"}
			<li>{title}</li>
			{stash:absolute_results}{absolute_results}{/stash:absolute_results}
		{/exp:structure_channel:entries}
		</ul>
		{/stash:content}
	{/exp:stash:set}

	Later in the same template or embedded template:

	{-- output the unordered list of entries --}
	{exp:stash:get name="content"}

	{-- output the absolute total count of the entries --}
	{exp:stash:get name="absolute_results"}


## {exp:stash:append} tag pair	
Works the same as {exp:stash:set}, except the value is appended to an existing variable.

### Example usage of append, with match/against:

	{exp:channel:entries channel="people" sort="asc" orderby="person_lname"}	
		{exp:stash:append name="people_a_f" match="#^[A-F]#" against="{person_lname}"}
			<li><a href="/people/{entry_url}" title="view profile">{title}</a></li>
		{/exp:stash:append}
	{/exp:channel:entries}

The above would capture all 'people' entries whose last name {person_lname} starts with A-F.

## {exp:stash:prepend} tag pair	
Works the same as {exp:stash:set}, except the value is prepended to an existing variable.

## {exp:stash:set_value} single tag
Works the same as {exp:stash:set}, except the value is passed as a parameter. This can be useful for when you need to use a plugin as a tag parameter (always use with parse="inward"). For example:

	{exp:stash:set_value name="title" value="{exp:another:tag}" type="snippet" parse="inward"}

In this case {title} would be set to the parsed value of {exp:another:tag}

## {exp:stash:append_value} single tag
Works the same as {exp:stash:append}, except the value is passed as a parameter.

## {exp:stash:prepend_value} single tag
Works the same as {exp:stash:prepend}, except the value is passed as a parameter.
	
## {exp:stash:get}

### name = [string]
The name of your variable (required)

### type = ['variable'|'snippet']
The type of variable to retrieve (optional, default is 'variable').

### dynamic = ['yes'|'no']
Look in the $_POST and $_GET superglobals arrays, for the variable (optional, default is 'no').
If Stash doesn't find the variable in the superglobals, it will look in the uri segment array for the variable name and takes the value from the next segment, e.g.: /variable_name/variable_value

### save = ['yes'|'no']
When using dynamic="yes", do you want to store the dynamic value in the database so that it persists across page loads? (optional, default is 'no')

### refresh = [int]
When using save="yes", this parameter sets the number of minutes to store the variable (optional, default is 1440 - or one day)

### default = [string]
Default value to return if variable is not set or empty (optional, default is an empty string). If a default value is supplied and the variable has not been set previously, then the variable will be set in the user's session. Thus subsequent attempt to get the variable will return the default value specified by the first call.

### output = ['yes'|'no']
Do you want to output the variable or just get the variable quietly in the background? (optional, default is 'yes')

### context = [string]
If the variable was defined within a context, set it here
Tip: you can also hardcode the context in the variable name, and use '@' to refer to the current context:

	{exp:stash:get name="@:title"}
	{exp:stash:get name="news:title"}

### scope = ['user'|'site']
Is the variable locally scoped to the User's session, or global (set for everyone who visits the site) (optional, default is 'user').
Note: use the same scope that you used when you set the variable.

### strip_tags = ['yes'|'no']
Strip HTML tags from the returned variable? (optional, default is 'no').

### strip_curly_braces = ['yes'|'no']
Strip curly braces ( { and } ) from the returned variable? (optional, default is 'no').

### Example usage

	{exp:stash:get name="title"}
	
### Advanced usage	
	Let's say you have a search form and you need to register a form field value and persist it across page views:
	
	{exp:stash:get name="my_form_field_name" type="snippet" dynamic="yes" cache="yes" refresh="30"}
	
	In an embedded template we have a {exp:channel:entries} tag producing a paginated listing of entries:
	{exp:channel:entries search:custom_field="{my_form_field_name}" disable="member_data|pagination|categories"}
		...
	{/exp:channel:entries}

## stash::get('name', 'type', 'scope')

The get() method is also available to use in PHP-enabled templates using a static function call. With PHP enabled *on output*, this allows you to access the value of a variable at the end of the parsing, after tags have been processed and rendered. Note that you must use a stash tag somewhere in the template for this to work, and PHP must be enabled on OUTPUT.

### Example usage
	<?php echo stash::get('title') ?>

	If you have short open tags enabled:
	<?= stash::get('title') ?>

## {exp:stash:context}
### name = [string]
The name of your context (required)

Set the current context (namespace) for variables that you set/get.

### Example usage

	{exp:stash:context name="news"}

	{!-- @ refers to the current context 'news' --}
	{exp:stash:set name="title" context="@" type="snippet"}
	My string
	{/exp:stash:set}

	{!-- now you can retrieve the variable in one of the following ways --}
	{exp:stash:get name="title" context="@" type="snippet"}
	{exp:stash:get name="title" context="news" type="snippet"}
	{exp:stash:get name="@:title" type="snippet"}
	{exp:stash:get name="news:title" type="snippet"}
	{@:title}

## {exp:stash:not_empty}
Has exactly the same parameters as {exp:stash:get}
Returns 0 or 1 depending on whether the variable is empty or not. Useful for conditionals.

### Example usage
	{if {exp:stash:not_empty name="my_variable" type="snippet"}}
		{my_variable}								
	{/if}
	
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
