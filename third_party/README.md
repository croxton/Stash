#Stash

* Author: [Mark Croxton](http://hallmark-design.co.uk/)

## Version 1.0.0

* Requires: ExpressionEngine 2

## Description

Stash allows you to stash text and snippets of code for reuse throughout your templates. Variables can be set dynamically from the $_GET or $_POST superglobals and can optionally be cached in the database for persistence across pages.

Stash variables that you create are available to templates embedded below the level at which you are using the tag, or later in the parse order of the current template.

Stash is inspired by John D Wells' article on [template partials](http://johndwells.com/blog/homegrown-plugin-to-create-template-partials-for-expressionengine), and Rob Sanchez's [Dynamo](https://github.com/rsanchez/dynamo) plugin. 

## Installation

1. Copy the stash folder to ./system/expressionengine/third_party/

## {exp:stash:set} tag pair

### name = [string]
The name of your variable (required)

### type = ['variable'|'snippet']
The type of variable to create (optional, default is 'variable').
A 'variable' is stored in the session, and must be retrieved using {exp:stash:get name=""}.
A 'snippet' works just like snippets in ExpressionEngine, and can be used directly as a tag {my_variable}
If using snippets, please be careful to namespace them so as not to overwrite any existing EE globals. 

### cache = ['yes'|'no']
Do you want to store the variable in the database so that it persists across page loads? (optional, default is 'no')

### refresh = [int]
The number of minutes to store the variable (optional, default is 1440 - or one day)

### Example usage
	{exp:channel:entries limit="1" disable="member_data|pagination|categories"}	
		{exp:stash:set name="title" type="snippet"}{title}{/exp:stash:set}
	{/exp:channel:entries}
	
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
The number of minutes to store the variable (optional, default is 1440 - or one day)

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
