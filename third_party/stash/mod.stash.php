<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Set and get template variables, EE snippets and persistent variables.
 *
 * @package             Stash
 * @version				2.1.0
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2011 Hallmark Design
 * @license             http://creativecommons.org/licenses/by-nc-sa/3.0/
 * @link                http://hallmark-design.co.uk
 */

class Stash {

	public $EE;
	public $site_id;
	public $path;
	protected $xss_clean;
	protected $replace;
	protected $type;
	protected $parse_tags = FALSE;
	protected $parse_vars = NULL;
	protected $parse_conditionals = FALSE;
	protected $parse_depth = 1;
	protected $parse_complete = FALSE;
	protected $bundle_id = 1;
	protected static $context = NULL;
	protected static $bundles = array();
	private $_update = FALSE;
	private $_append = TRUE;
	private $_stash;
	private $_stash_cookie = 'stashid';
	private $_session_id;
	private $_ph = array();
	private $_list_delimiter = '|+|';
	private $_list_row_delimiter = '|&|';
	private $_list_row_glue = '|=|';

	/*
	 * Constructor
	 */
	public function __construct()
	{
		$this->EE =& get_instance();
		
		// load dependencies - make sure the package path is available in case the class is being called statically
		$this->EE->load->add_package_path(PATH_THIRD.'stash/', TRUE);
		$this->EE->lang->loadfile('stash');
		$this->EE->load->model('stash_model');

		 // site id
		$this->site_id = $this->EE->config->item('site_id');
		
		// file basepath
		$this->path = $this->EE->config->item('stash_file_basepath') ? $this->EE->config->item('stash_file_basepath') : APPPATH . 'stash/';
		
		// xss scripting protection
		$this->xss_clean = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('xss_clean'));
		
		// if the variable is already set, do we want to replace it's value? Default = yes
		$this->replace = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('replace', 'yes'));
		
		// do we want to parse any tags and variables inside tagdata? Default = no	
		$this->parse_tags = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('parse_tags'));
		$this->parse_vars = $this->EE->TMPL->fetch_param('parse_vars', NULL);
		
		// legacy behaviour: if parse_vars is null but parse tags is true, we should make sure vars are parsed too
		if ($this->parse_tags && $this->parse_vars == NULL)
		{
			$this->parse_vars = TRUE;
		}
		else
		{
			$this->parse_vars = (bool) preg_match('/1|on|yes|y/i', $this->parse_vars);
		}
		
		// parsing: how many passes of the template should we make? (more passes = more overhead). Default = 1
		$this->parse_depth = preg_replace('/[^0-9]/', '', $this->EE->TMPL->fetch_param('parse_depth', 1));
		
		// parsing: parse advanced conditionals. Default = no
		$this->parse_conditionals = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('parse_conditionals'));
		
		// stash type, default to 'variable'
		$this->type = strtolower( $this->EE->TMPL->fetch_param('type', 'variable') );
		
		// create a stash array in the session if we don't have one
		if ( ! array_key_exists('stash', $this->EE->session->cache) )
		{
			$this->EE->session->cache['stash'] = array();
		}	
		
		// determine the stash storage location
		if ($this->type === 'variable')
		{
			// we're setting/getting a 'native' stash variable
			$this->_stash =& $this->EE->session->cache['stash'];
		}
		elseif ($this->type === 'snippet' || $this->type === 'global')
		{
			// we're setting/getting a global variable {snippet}
			$this->_stash =& $this->EE->config->_global_vars;
		}
		else
		{
			$this->EE->output->show_user_error('general', $this->EE->lang->line('unknown_stash_type') . $this->type);
		}

		// fetch the stash session id
		if ( ! isset($this->EE->session->cache['stash']['_session_id']) )
		{	
			// do we have a session cookie?	
			if ( ! $this->EE->input->cookie($this->_stash_cookie) )
			{ 
				// NO cookie - let's generate a unique id
				$unique_id = $this->EE->functions->random();
				
				// add to stash array
				$this->EE->session->cache['stash']['_session_id'] = $unique_id;
				
				// create a cookie, set to 2 hours
				$this->EE->functions->set_cookie($this->_stash_cookie, $unique_id, 7200);
			}
			else
			{	
				// YES - cookie exists
				$this->EE->session->cache['stash']['_session_id'] = $this->EE->input->cookie($this->_stash_cookie);

				// get the last activity
				if ( $last_activity = $this->EE->stash_model->get_last_activity_date(
						$this->EE->session->cache['stash']['_session_id'], 
						$this->site_id
				))
				{
					// older than 5 minutes? Let's regenerate the cookie and update the db
					if ( $last_activity + 300 < $this->EE->localize->now)
					{			
						// overwrite cookie
						$this->EE->functions->set_cookie($this->_stash_cookie, $this->EE->session->cache['stash']['_session_id'], 7200);

						// update db last activity record for this session id
						$this->EE->stash_model->update_last_activity(
							$this->EE->session->cache['stash']['_session_id'],
							$this->site_id
						);
						
						// cleanup - delete ANY last activity records older than 2 hours
						$this->EE->stash_model->prune_last_activity(7200);
						
						// cleanup - delete any keys with expiry date older than right now 
						$this->EE->stash_model->prune_keys();	
					}
				}
				else
				{
					// no last activity exists, let's create a record for this session id
					$this->EE->stash_model->insert_last_activity(
						$this->EE->session->cache['stash']['_session_id'],
						$this->site_id,
						$this->EE->session->userdata['ip_address'].'|'.$this->EE->session->userdata['user_agent']
					);
				}					
			}
		}
		
		// create a reference to the session id
		$this->_session_id =& $this->EE->session->cache['stash']['_session_id'];
	}
	
	/*
	================================================================
    Template tags
	================================================================
	*/
	
	/**
	 * Set content in the current session, optionally save to the database
	 *
	 * @access public
	 * @param  mixed 	 $params The name of the variable to retrieve, or an array of key => value pairs
	 * @param  string 	 $value The value of the variable
	 * @param  string 	 $type  The type of variable
	 * @param  string 	 $scope The scope of the variable
	 * @return void 
	 */
	public function set($params = array(), $value='', $type='variable', $scope='user')
	{	
		/* Sample use
		---------------------------------------------------------
		{exp:stash:set name="title" type="snippet"}A title{/exp:stash:set}
		
		OR static call within PHP enabled templates or other add-on: 
		<?php stash::set('title', 'My title') ?>
		--------------------------------------------------------- */
		
		// is this method being called statically from PHP?
		if ( func_num_args() > 0 && !(isset($this) && get_class($this) == __CLASS__))
		{
			// make sure we have a clean array in case the class has already been instatiated
			$this->EE->TMPL->tagparams = array();
			
			if ( is_array($params))
			{
				$this->EE->TMPL->tagparams = $params;
			}
			else
			{
				$this->EE->TMPL->tagparams['name']    = $params;
				$this->EE->TMPL->tagparams['type']    = $type;
				$this->EE->TMPL->tagparams['scope']   = $scope;
			}
		
			$this->EE->TMPL->tagdata = $value;
		
			// as this function is called statically, 
			// we need to get an instance of this object and run get()
			$self = new self();	
			return $self->set();
		}
		
		// do we want to set the variable?
		$set = TRUE;
		
		// var name
		$name = strtolower($this->EE->TMPL->fetch_param('name', FALSE));		
		
		// context handling
		$context = $this->EE->TMPL->fetch_param('context', NULL);
		
		if ( !! $name)
		{
			if ($context !== NULL && count( explode(':', $name) == 1 ) )
			{
				$name = $context . ':' . $name;
				$this->EE->TMPL->tagparams['context'] = NULL;
			}
		}
		
		// replace '@' placeholders with the current context
		$stash_key = $this->_parse_context($name);
		
		// scope
		$scope 	= strtolower($this->EE->TMPL->fetch_param('scope', 'user')); // user|site
		
		// do we want this tag to return it's tagdata? (default: no)
		$output = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('output'));
		
		// do we want to save this variable in a bundle?
		$bundle = $this->EE->TMPL->fetch_param('bundle', NULL); // save in a bundle?
		
		// do we want to replace an existing variable?
		if ( !! $name && ! $this->replace && ! $this->_update)
		{
			// try to get existing value
			$existing_value = FALSE;
			
			if ( array_key_exists($stash_key, $this->_stash))
			{
				$existing_value = $this->_stash[$name];
			}
			else 
			{
				// narrow the scope to user?
				$session_id = $scope === 'user' ? $this->_session_id : '';
				
				$existing_value = $this->EE->stash_model->get_key(
					$stash_key, 
					$this->bundle_id,
					$session_id, 
					$this->site_id
				);
			}

			if ( !! $existing_value)
			{
				// yes, it's already been stashed
				$this->EE->TMPL->tagdata = $this->_stash[$name] = $existing_value;
				
				// don't overwrite existing value
				$set = FALSE;
			}
			unset($existing_value);
		}
		
		// do we want to ignore empty tagdata values?
		if ( $not_empty = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('not_empty')) )
		{
			if ( ! $this->not_empty())
			{
				$set = FALSE;
			}
		}
		
		if ($set)
		{
			if ( ($this->parse_tags || $this->parse_vars) && ! $this->parse_complete)
			{	
				$this->_parse_sub_template($this->parse_tags, $this->parse_vars, $this->parse_conditionals, $this->parse_depth);
				$this->parse_complete = TRUE; // don't run again
			}
			
			// apply any string manipulations
			$this->EE->TMPL->tagdata = $this->_clean_string($this->EE->TMPL->tagdata);

			if ( !! $name )
			{					
				// get params
				$label 			= strtolower($this->EE->TMPL->fetch_param('label', $name));
				$save 			= (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('save'));						
				$refresh 		= $this->EE->TMPL->fetch_param('refresh', 1440); // minutes (1440 = 1 day)	
				$match 			= $this->EE->TMPL->fetch_param('match', NULL); // regular expression to test value against
				$against 		= $this->EE->TMPL->fetch_param('against', $this->EE->TMPL->tagdata); // text to apply test against
				$filter			= $this->EE->TMPL->fetch_param('filter', NULL); // regex pattern to search for
				$default 		= $this->EE->TMPL->fetch_param('default', NULL); // default value
				$delimiter 		= $this->EE->TMPL->fetch_param('delimiter', '|'); // implode arrays using this delimiter
				
				// regex match
				if ( $match !== NULL && preg_match('/^#(.*)#$/', $match))
				{	
					$is_match = $this->_matches($match, $against);
					
					// did it fail to match the filter?
					if ( ! $is_match )
					{
						// if a default has been specified fallback to it
						if (! is_null($default))
						{
							$this->EE->TMPL->tagdata = $default;
						}
						else
						{
							return;
						}
					} 
				}
				
				// regex filter
				if ( $filter !== NULL)
				{
					preg_match($filter, $this->EE->TMPL->tagdata, $found);
					if (isset($found[1]))
					{
						$this->EE->TMPL->tagdata = $found[1];
					}	
				}
				
				// make sure we're working with a string
				// if we're setting a variable from a global ($_POST, $_GET etc), it could be an array
				if ( is_array($this->EE->TMPL->tagdata))
				{
					$this->EE->TMPL->tagdata = array_filter($this->EE->TMPL->tagdata, 'strlen');
					$this->EE->TMPL->tagdata = implode($delimiter, $this->EE->TMPL->tagdata);
				}
			
				if ( $this->_update )
				{
					// We're updating a variable, so lets see if it's in the session or db
					if ( ! array_key_exists($name, $this->_stash))
					{
						$this->_stash[$name] = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));
					}
			
					// Append or prepend?
					if ( $this->_append )
					{
						$this->_stash[$name] .= $this->EE->TMPL->tagdata;
					}
					else
					{
						$this->_stash[$name] = $this->EE->TMPL->tagdata.$this->_stash[$name];
					}
				} 
				else
				{
					$this->_stash[$name] = $this->EE->TMPL->tagdata;
				}
			
				if ($save)
				{	
					// optionally clean data before inserting
					$parameters = $this->_stash[$name];
				
					if ($this->xss_clean)
					{	
						$this->EE->security->xss_clean($parameters);
					}

					// what's the intended variable scope? 
					if ($scope === 'site')
					{
						$session_filter = '_global';
					}
					else
					{
						$session_filter =& $this->_session_id;
					}
					
					// let's check if there is an existing record, and that that it matches the new one exactly
					$result = $this->EE->stash_model->get_key($stash_key, $this->bundle_id, $session_filter, $this->site_id);

					if ( $result !== FALSE)
					{
						// record exists, but is it identical?
						// allow append/prepend if the stash key has been created *in this page load*
						$cache_key = $stash_key. '_'. $this->bundle_id .'_' .$this->site_id . '_' . $session_filter;
						
						if ( $result !== $parameters && ($this->replace || ($this->_update && $this->EE->stash_model->is_inserted_key($cache_key)) ) )
						{	
							// nope - update
							$this->EE->stash_model->update_key(
								$stash_key,
								$this->bundle_id,
								$session_filter,
								$this->site_id,
								$this->EE->localize->now + ($refresh * 60),
								$parameters
							);
						}
					}
					else
					{	
						// no record - insert one
						$this->EE->stash_model->insert_key(
							$stash_key,
							$this->bundle_id,
							$session_filter,
							$this->site_id,
							$this->EE->localize->now + ($refresh * 60),
							$parameters,
							$label
						);
					}
				}
			}
			else
			{
				// no name supplied, so let's assume we want to set sections of content within tag pairs
				// {stash:my_variable}...{/stash:my_variable}
				$vars = array();
				$tagdata = $this->EE->TMPL->tagdata;
			
				// context handling
				if ( $context !== NULL ) 
				{
					$prefix = $context . ':';
					$this->EE->TMPL->tagparams['context'] = NULL;
				}
				else
				{
					$prefix = '';
				}
				
				// if the tagdata has been parsed, we need to generate a new array of tag pairs
				// this permits dynamic tag pairs, e.g. {stash:{key}}{/stash:{key}} 
				if ($this->parse_complete)
				{
					$tag_vars = $this->EE->functions->assign_variables($this->EE->TMPL->tagdata);
					$tag_pairs = $tag_vars['var_pair'];
				}
				else
				{
					$tag_pairs =& $this->EE->TMPL->var_pair;
				}
			
				foreach($tag_pairs as $key => $val)
				{
					if (strncmp($key, 'stash:', 6) ==  0)
					{
						$pattern = '/'.LD.$key.RD.'(.*)'.LD.'\/'.$key.RD.'/Usi';
						preg_match($pattern, $tagdata, $matches);
						if (!empty($matches))
						{		
							// set the variable, but cleanup first in case there are any nested tags
							$this->EE->TMPL->tagparams['name'] = $prefix . str_replace('stash:', '', $key);
							$this->EE->TMPL->tagdata = preg_replace('/'.LD.'stash:[a-zA-Z0-9\-_]+'.RD.'(.*)'.LD.'\/stash:[a-zA-Z0-9\-_]+'.RD.'/Usi', '', $matches[1]);
							$this->parse_complete = TRUE; // don't allow tagdata to be parsed
							$this->set();
						}	
					}
				}
			
				// reset tagdata to original value
				$this->EE->TMPL->tagdata = $tagdata;
				unset($tagdata);
			}
		}
		
		if ( !! $name)
		{
			if ( $bundle !== NULL)
			{
				if ( ! isset(self::$bundles[$bundle]))
				{
					self::$bundles[$bundle] = array();
				}
				self::$bundles[$bundle][$name] = $this->_stash[$name];
			}
			
			$this->EE->TMPL->log_item('Stash: SET '. $name . ' to value ' . $this->_stash[$name]);
			
		}
		
		if ($output)
		{
			return $this->EE->TMPL->tagdata;
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Get content from session, database, $_POST/$_GET superglobals or file
	 *
	 * @access public
	 * @param  mixed 	 $params The name of the variable to retrieve, or an array of key => value pairs
	 * @param  string 	 $type  The type of variable
	 * @param  string 	 $scope The scope of the variable
	 * @return string 
	 */
	public function get($params='', $type='variable', $scope='user')
	{		
		/* Sample use
		---------------------------------------------------------
		{exp:stash:get name="title"}
		
		OR static call within PHP enabled templates or other add-on: 
		<?php echo stash::get('title') ?>
		--------------------------------------------------------- */
		
		// is this method being called statically from PHP?
		if ( func_num_args() > 0 && !(isset($this) && get_class($this) == __CLASS__))
		{
			// make sure we have a clean array in case the class has already been instatiated
			$this->EE->TMPL->tagparams = array();
			
			if ( is_array($params))
			{
				$this->EE->TMPL->tagparams = $params;
			}
			else
			{
				$this->EE->TMPL->tagparams['name']    = $params;
				$this->EE->TMPL->tagparams['type']    = $type;
				$this->EE->TMPL->tagparams['scope']   = $scope;
			}
		
			// as this function is called statically, 
			// we need to get an instance of this object and run get()
			$self = new self();	
			return $self->get();
		}

		$name 			= strtolower($this->EE->TMPL->fetch_param('name'));
		$default 		= $this->EE->TMPL->fetch_param('default', ''); // default value
		$dynamic 		= (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('dynamic'));		
		$scope 			= strtolower($this->EE->TMPL->fetch_param('scope', 'user')); // user|site
		$bundle 		= $this->EE->TMPL->fetch_param('bundle', NULL); // save in a bundle?
		$match 			= $this->EE->TMPL->fetch_param('match', NULL); // regular expression to test value against
		$filter			= $this->EE->TMPL->fetch_param('filter', NULL); // regex pattern to search for
		
		// do we want this tag to return the value, or just set the variable quietly in the background?
		$output = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('output', 'yes'));
		
		// parse any vars in the $name parameter?
		if ($this->parse_vars)
		{
			$name = $this->_parse_template_vars($name);
		}
		
		// low search support - do we have a query string?
		$low_query = $this->EE->TMPL->fetch_param('low_query', NULL);

		// context handling
		$context	= $this->EE->TMPL->fetch_param('context', NULL);
		$global_name = $name;
		
		if ($context !== NULL && count( explode(':', $name) == 1 ) )
		{
			$name = $context . ':' . $name;
			$this->EE->TMPL->tagparams['context'] = NULL;
		}
		
		// read from file?
		$file = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('file'));
		$file_name = $this->EE->TMPL->fetch_param('file_name', FALSE); // default value
		
		if ( !! $file_name)
		{
			$file = TRUE;
		}
		else
		{
			$file_name = $name;
		}
		
		// the variable value
		$value = NULL;
		
		// do we want to set the variable?
		$set = FALSE;
		
		// is it a segment? We need to support these in stash template files
		if (strncmp($name, 'segment_', 8) == 0)
		{
			$seg_index = substr($name, 8);
			$value = $this->EE->uri->segment($seg_index);
		}

		// let's see if it's been stashed before
		elseif ( array_key_exists($name, $this->_stash))
		{
			$value = $this->_stash[$name];			
		}
		
		// Not found in globals
		else
		{
			// has it been bundled?
			if ( ! is_null($bundle))
			{
				if (isset(self::$bundles[$bundle][$name]))
				{
					$value = $this->_stash[$name] = self::$bundles[$bundle][$name];
				}
				//$set = TRUE;
			}
			elseif ( ! $this->_update)
			{
				// let's look in the database table cache, if we're not appending/prepending
				
				// narrow the scope to user?
				$session_id = $scope === 'user' ? $this->_session_id : '';
			
				// replace '@' placeholders with the current context
				$stash_key = $this->_parse_context($name);
					
				// look for our key
				if ( $parameters = $this->EE->stash_model->get_key(
					$stash_key, 
					$this->bundle_id,
					$session_id, 
					$this->site_id
				))
				{	
					// save to session 
					$value = $this->_stash[$name] = $parameters;
				}
			}

			// Are we looking for a superglobal or uri segment?
			if ( ($dynamic && $value == NULL) || ($dynamic && $this->replace) )
			{	
				$from_global = FALSE;
					
				// low search support
				if ($low_query !== NULL)
				{
					// has the query string been supplied or is it in POST?
					if (strncmp($low_query, 'stash:', 6) == 0)
					{
						$low_query = substr($low_query, 6);
						$low_query = $this->_stash[$low_query];
					}

					$low_query = @unserialize(base64_decode(str_replace('_', '/', $low_query)));

					if (isset( $low_query[$global_name] ))
					{
						$from_global = $low_query[$global_name];
						unset($low_query);
					}
					else
					{
						// set to empty value
						$from_global = '';
					}
				}
				
				// or is it in the $_POST or $_GET superglobals ( run through xss_clean() )?
				if ( $from_global === FALSE )
				{
					$from_global = $this->EE->input->get_post($global_name, TRUE);
				}
				
				if ( $from_global === FALSE )
				{
					// no, so let's check the uri segments
					$segs = $this->EE->uri->segment_array();

					foreach ( $segs as $index => $segment )
					{
					    if ( $segment == $global_name && array_key_exists( ($index+1), $segs) )
						{
							$from_global = $segs[($index+1)];
							break;
						}
					}
				}
				
				if ( $from_global !== FALSE )
				{
					// save to stash, and optionally to database, if save="yes"
					$value = $from_global;
					$set = TRUE;
				}
			}
			
			// Are we reading a file?
			if ( ($file && $value == NULL) || ($file && $this->replace) )
			{					
				$this->EE->TMPL->log_item("Stash: reading from file");
				
				// construct a filepath. Here contexts become folders...
				$this->EE->load->helper('url_helper');
				
				// make sure we have a url encoded path
				$file_path = explode(':', $file_name);
				foreach($file_path as &$part)
				{
					$part = url_title($part);
				}
				
				$file_path = $this->path . implode('/', $file_path) . '.html';

				if ( file_exists($file_path))
				{		
					$value = file_get_contents($file_path);
					$set = TRUE;
				}
				else
				{
					$this->EE->output->show_user_error('general', sprintf($this->EE->lang->line('stash_file_not_found'), $file_path));
					return;
				}
			}
			
			
			// set default if we still don't have a value
			if ( $value == NULL)
			{	
				$value = $default;
				$set = TRUE;	
			}
			
			// create/update value of variable if required
			// note: don't save if we're updating a variable (to avoid recursion)
			if ( $set && ! $this->_update)
			{
				$this->EE->TMPL->tagparams['name'] = $name;
				$this->EE->TMPL->tagparams['output'] = 'yes';
				$this->EE->TMPL->tagdata = $value;
				$this->replace = TRUE;
				$value = $this->set();
			}
		}
		
		// set to default value if it is exactly '' (this permits '0' to be a valid Stash value)
		if ( $value === '' && $default !== '')
		{	
			$value = $default;	
		}
			
		$this->EE->TMPL->log_item('Stash: RETRIEVED '. $name . ' with value ' . $value);
		
		// save to bundle
		if ( $bundle !== NULL)
		{
			if ( ! isset(self::$bundles[$bundle]))
			{
				self::$bundles[$bundle] = array();
			}
			self::$bundles[$bundle][$name] = $value;
		}			 
		
		// output
		if ($output)
		{
			$value =  $this->_parse_output($value, $match, $filter, $default);
			
			// if we're reading from a file (or a cached file) we need to parse early global vars
			// and remove any leftover {stash:} vars
			if ($file)
			{
				$value = $this->_parse_template_vars($value);
				
				// cleanup of leftover/undeclared stash: single and pairs
				if (strpos($value , LD.'stash:') !== FALSE)
				{
					$value = preg_replace('#\{/?stash:([^!]+?)\}#', '', $value);
				}
			}
			return $value;
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Append the specified value to an already existing variable.
	 *
	 * @access public
	 * @return void 
	 */
	public function append()
	{
		$this->_update = TRUE;
		$this->_append = TRUE;
		return $this->set();
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Prepend the specified value to an already existing variable.
	 *
	 * @access public
	 * @return void 
	 */
	public function prepend()
	{
		$this->_update = TRUE;
		$this->_append = FALSE;
		return $this->set();
	}
		
	// ---------------------------------------------------------
	
	/**
	 * Single tag version of set(), for when you need to use a 
	 * plugin as a tag parameter (always use with parse="inward")
	 * 
	 *
	 * @access public
	 * @param bool 	 $update Update an existing stashed variable
	 * @return void 
	 */
	public function set_value()
	{	
		/* Sample use
		---------------------------------------------------------
		{exp:stash:set_value name="title" value="{exp:another:tag}" type="snippet" parse="inward"}
		--------------------------------------------------------- */
		
		$this->EE->TMPL->tagdata = $this->EE->TMPL->fetch_param('value', FALSE);
		
		if ( $this->EE->TMPL->tagdata !== FALSE )
		{
			return $this->set();
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Single tag version of append()
	 *
	 * @access public
	 * @return void 
	 */
	public function append_value()
	{
		$this->_update = TRUE;
		$this->_append = TRUE;
		return $this->set_value();
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Single tag version of prepend()
	 *
	 * @access public
	 * @return void 
	 */
	public function prepend_value()
	{
		$this->_update = TRUE;
		$this->_append = FALSE;
		return $this->set_value();
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Set the current context
	 *
	 * @access protected
	 * @return void
	 */
	public function context()
	{
		if ( !! $name = strtolower($this->EE->TMPL->fetch_param('name', FALSE)) )
		{
			self::$context = $name;
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Checks if a variable or string is empty or non-existent, handy for conditionals
	 *
	 * @access public
	 * @param $string a string to test
	 * @return integer
	 */
	public function not_empty($string = NULL)
	{
		/* Sample use
		---------------------------------------------------------
		Check a native stash variable, global variable or snippet is not empty:
		{if {exp:stash:not_empty type="snippet" name="title"} }
			Yes! {title} is not empty
		{/if}
		
		Check any string or variable is not empty even if it's not been Stashed:
		{if {exp:stash:not_empty:string}{my_string}{/exp:stash:not_empty:string} }
			Yes! {my_string} is not empty
		{/if}
		--------------------------------------------------------- */
		if ( ! is_null($string))
		{
			$test = $string;
		}
		elseif ( $this->EE->TMPL->tagdata )
		{
			// parse any vars in the string we're testing
			$this->_parse_sub_template(FALSE, TRUE);
			$test = $this->EE->TMPL->tagdata;
		}
		else
		{
			$test = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));
		}
		
		$value  = str_replace( array("\t", "\n", "\r", "\0", "\x0B"), '', trim($test));
		return empty( $value ) ? 0 : 1;
	}	
	
	// ---------------------------------------------------------
	
	/**
	 * Serialize a multidimenisional array and save as a variable
	 *
	 * @access public
	 * @return string 
	 */
	public function set_list()
	{	
		/* Sample use
		---------------------------------------------------------
		{exp:stash:set_list name="blog_entries"}
        	{stash:item_title}{title}{/stash:item_title}
        	{stash:item_img_url}{img_url}{/stash:item_img_url}
        	{stash:item_copy}{copy}{/stash:item_copy}
    	{/exp:stash:set_list}
		--------------------------------------------------------- */
		
		// do any parsing and string transforms before making the list
		$this->EE->TMPL->tagdata = $this->_parse_output($this->EE->TMPL->tagdata);
		
		//  get the stash var pairs values
		$this->_serialize_stash_tag_pairs();
		
		if ( $this->not_empty($this->EE->TMPL->tagdata))
		{
			return $this->set();	
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Append to array
	 *
	 * @access public
	 * @return string 
	 */
	public function append_list()
	{	
		$this->EE->TMPL->tagdata = $this->_parse_output($this->EE->TMPL->tagdata);
		$this->_serialize_stash_tag_pairs();
		
		if ( $this->not_empty($this->EE->TMPL->tagdata))
		{
			$this->EE->TMPL->tagdata = $this->_list_delimiter . $this->EE->TMPL->tagdata;
			return $this->append();	
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Prepend to array
	 *
	 * @access public
	 * @return string 
	 */
	public function prepend_list()
	{	
		$this->EE->TMPL->tagdata = $this->_parse_output($this->EE->TMPL->tagdata);
		$this->_serialize_stash_tag_pairs();
		
		if ( $this->not_empty($this->EE->TMPL->tagdata))
		{
			$this->EE->TMPL->tagdata .=  $this->_list_delimiter;
			return $this->prepend();	
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Retrieve a serialised array of items, explode and replace into tagdata
	 *
	 * @access public
	 * @return string 
	 */
	public function get_list()
	{		
		/* Sample use
		---------------------------------------------------------
		{exp:stash:get_list name="page_items" orderby="item_title" sort="asc"}
			<h2>{item_title}</h2>
   			<img src="{item_img_url}" />
   			{item_copy}
		{/exp:stash:get_list}
		--------------------------------------------------------- */	
		$sort 			= strtolower($this->EE->TMPL->fetch_param('sort', 'asc'));
		$sort_type 		= strtolower($this->EE->TMPL->fetch_param('sort_type', 'string')); // string || integer
		$orderby 		= $this->EE->TMPL->fetch_param('orderby', FALSE);
		$limit 			= $this->EE->TMPL->fetch_param('limit',  FALSE);
		$offset 		= $this->EE->TMPL->fetch_param('offset', FALSE);
		$default 		= $this->EE->TMPL->fetch_param('default', ''); // default value
		$filter			= $this->EE->TMPL->fetch_param('filter', NULL); // regex pattern to search final output for
		
		$list_html 		= '';
		$list_markers	= array();

		// retrieve the list array
		$list = $this->_rebuild_list();

		// return no results if this variable has no value
		if ($list == '')
		{
			return $this->EE->TMPL->no_results();
		}
		
		// order by multidimensional array key
		if ($orderby)
		{
			if ($orderby == "random")
			{
				shuffle($list);
			}
			else
			{
				// this will return an ordered array with sort ascending 
				$list = $this->sort_by_key($list, $orderby, 'sort_by_'.$sort_type);
			}
		}
		
		// apply sort direction
		if ($sort == 'desc')
		{
			$list = array_reverse($list);
		}
		
		// add absolute count to the ordered/sorted items
		$i=0;
		foreach($list as $key => &$value)
		{
			$i++;
			$value['absolute_count'] = $i;
		}
		
		// record the total number of list rows
		$list_markers['absolute_results'] = count($list);
		
		// slice array depending on limit/offset
		if ($limit && $offset)
		{
			$list = array_slice($list, $offset, $limit);
		}
		elseif ($limit)
		{
			$list = array_slice($list, 0, $limit);
		}
		elseif ($offset)
		{
			$list = array_slice($list, $offset);
		}
		
		if (count($list) > 0)
		{		
			// replace into template		
			$list_html = $this->EE->TMPL->parse_variables($this->EE->TMPL->tagdata, $list);
		
			// disable backspace parameter as parse_variables does it for you...
			$this->EE->TMPL->tagparams['backspace'] = FALSE;
		
			// parse other markers
			$list_html = $this->EE->TMPL->parse_variables_row($list_html, $list_markers);
		
			// now apply final output transformations / parsing
			return $this->_parse_output($list_html, NULL, $filter, $default);
		}
		else
		{
			return $this->EE->TMPL->no_results();
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Retrieve the item count for a given list
	 *
	 * @access public
	 * @return string 
	 */
	public function list_count()
	{
		$match 			= $this->EE->TMPL->fetch_param('match', NULL); // regular expression to each list item against
		$against 		= $this->EE->TMPL->fetch_param('against', NULL); // key to test $match against	
				
		// retrieve the list array
		$list = $this->_rebuild_list();

		// return 0 if this variable has no value
		if ($list == '') return '0';

		$count = count($list);
		return "$count"; // make sure we return a string
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Restore values for a given bundle
	 *
	 * @access public
	 * @return string
	 */
	public function get_bundle()
	{
		/* Sample use
		---------------------------------------------------------
		{exp:stash:get_bundle name="contact_form" context="@" limit="5"}
			{contact_name}
		{/exp:stash:get_bundle}
		--------------------------------------------------------- */
		$out = '';
		
		if ( !! $bundle = strtolower($this->EE->TMPL->fetch_param('name', FALSE)) )
		{
			
			// get the bundle id, cache to memory for efficient reuse later
			$bundle_id = $this->EE->stash_model->get_bundle_by_name($bundle, $this->site_id);
			
			// does this bundle already exist?
			if ( $bundle_id )
			{	
				$bundle_array = array();
				$tpl = $this->EE->TMPL->tagdata;
				$this->bundle_id = $bundle_id;
				
				// get params
				$unique = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('unique', 'yes'));
				$index  = $this->EE->TMPL->fetch_param('index', NULL);	
				$context = $this->EE->TMPL->fetch_param('context', NULL);
				$scope = strtolower($this->EE->TMPL->fetch_param('scope', 'user')); // user|site

				// if this is a unique bundle, restore the bundled variables to static bundles array
				if ($unique || ! is_null($index))
				{		
					if ( $index !== NULL && $index > 0)
					{
						$bundle .= '_'.$index;
						$this->EE->TMPL->tagparams['name'] = $bundle;
					}
					
					// get bundle var
					$bundle_entry_key = $bundle;
					if ($bundle !== NULL && count( explode(':', $bundle) == 1 ) )
					{
						$bundle_entry_key = $context . ':' . $bundle;
					}
					$session_id = $scope === 'user' ? $this->_session_id : '';
					$bundle_entry_key = $this->_parse_context($bundle_entry_key);
					
					// look for our key
					if ( $bundle_value = $this->EE->stash_model->get_key(
						$bundle_entry_key, 
						$this->bundle_id,
						$session_id, 
						$this->site_id
					))
					{	
						$bundle_array[0] = unserialize($bundle_value);
						
						foreach ($bundle_array[0] as $key => $val)
						{
							self::$bundles[$bundle][$key] = $val;
						}	
					}	
				}
				else
				{
					// FUTURE FEATURE: get all entries for bundle with multiple rows
					
				}
				
				// replace into template
				if ( ! empty($tpl))
				{
					foreach($bundle_array as $vars)
					{	
						$out .= $this->EE->functions->var_swap($tpl, $vars);
					}
				}
				
				$this->EE->TMPL->log_item("Stash: RETRIEVED bundle ".$bundle);
			}
		}
		
		return $out;	
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Set values into a bundle
	 *
	 * @access public
	 * @return void 
	 */
	public function set_bundle()
	{
		/* Sample use
		---------------------------------------------------------
		{exp:stash:set_bundle name="contact_form"}
		--------------------------------------------------------- */
		
		if ( !! $bundle = strtolower($this->EE->TMPL->fetch_param('name', FALSE)) )
		{			
			if ( isset(self::$bundles[$bundle]))
			{
				// get params
				$bundle_label = strtolower($this->EE->TMPL->fetch_param('label', $bundle));
				$unique = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('unique', 'yes'));
				$bundle_entry_key = $bundle_entry_label = $bundle;
				
				// get the bundle id
				$bundle_id = $this->EE->stash_model->get_bundle_by_name($bundle, $this->site_id);
				
				// does this bundle already exist? Let's try to get it's id
				if ( ! $bundle_id )
				{
					// doesn't exist, let's create it
					$bundle_id = $this->EE->stash_model->insert_bundle(
						$bundle,
						$this->site_id,
						$bundle_label
					);		
				}
				elseif ( ! $unique)
				{
					// bundle exists, but do we want more than one entry per bundle?
					$entry_count = $this->EE->stash_model->bundle_entry_count($bundle_id, $this->site_id);
					if ($entry_count > 0)
					{
						$bundle_entry_key .= '_'.$entry_count;
						$bundle_entry_label = $bundle_entry_key;
					}
				}
				
				// stash the data under a single key
				$this->EE->TMPL->tagparams['name'] = $bundle_entry_key;
				$this->EE->TMPL->tagparams['label'] = $bundle_entry_label;
				$this->EE->TMPL->tagparams['save'] = 'yes';
				$this->EE->TMPL->tagdata = serialize(self::$bundles[$bundle]);
				$this->bundle_id = $bundle_id;
				
				unset(self::$bundles[$bundle]);
				return $this->set();	
			}
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Bundle up a collection of variables and save in the database
	 *
	 * @access public
	 * @return void 
	 */
	public function bundle()
	{
		/* Sample use
		---------------------------------------------------------
		{exp:stash:bundle name="contact_form" context="@" unique="no" type="snippet" refresh="10"}
			{exp:stash:get dynamic="yes" name="orderby" output="no" default="persons_last_name" match="#^[a-zA-Z0-9_-]+$#"}
			{exp:stash:get dynamic="yes" name="sort" output="no" default="asc" match="#^asc|desc$#"}
			{exp:stash:get dynamic="yes" name="filter" output="no" default="" match="#^[a-zA-Z0-9_-]+$#"}
			{exp:stash:get dynamic="yes" name="in" output="no" default="" match="#^[a-zA-Z0-9_-]+$#"}
			{exp:stash:get dynamic="yes" name="field" output="no" match="#^[a-zA-Z0-9_-]+$#" default="persons_last_name"}
		{/exp:stash:bundle}
		--------------------------------------------------------- */
		
		if ( !! $bundle = strtolower($this->EE->TMPL->fetch_param('name', FALSE)) )
		{
			// build a string of parameters to inject into nested stash tags
			$context = $this->EE->TMPL->fetch_param('context', NULL);
			$params = 'bundle="'.$bundle.'"';
			if ($context !== NULL )
			{
				$params .=	' context="'.$context.'"';
			}
			
			// add params to nested tags
			$this->EE->TMPL->tagdata = preg_replace( '/('.LD.'exp:stash:get|'.LD.'exp:stash:set)/i', '$1 '.$params, $this->EE->TMPL->tagdata);
			
			// get existing values from bundle
			$this->get_bundle();
			
			// parse stash tags in the bundle
			$this->_parse_sub_template();
			
			// save the bundle values
			$this->set_bundle();
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Flush the variables database cache for the current site (Super Admins only)
	 *
	 * @access public
	 * @return string 
	 */
	public function flush_cache()
	{
		if ($this->EE->session->userdata['group_title'] == "Super Admins")
		{
			$this->EE->stash_model->flush_cache($this->site_id);
			return $this->EE->lang->line('cache_flush_success');
		}
		else
		{
			// not authorised
			$this->EE->output->show_user_error('general', $this->EE->lang->line('not_authorized'));
		}
	}		
	
	/*
	================================================================
    Utility methods
	================================================================
	*/
	
	/**
	 * Match a regex against a string or array of strings
	 *
	 * @access private
	 * @param string $match A regular expression
	 * @param string/array $against array of strings to match regex against
	 * @return bool
	 */
	private function _matches($match, $against)
	{
		$is_match = TRUE;
		$match = $this->EE->security->entity_decode($match);

		if ( ! is_array($against)) 
		{
			$against = array($against);
		}
		else
		{
			// remove null values
			$against = array_filter($against, 'strlen');
		}
		
		// check every value in the array matches
		foreach($against as $part)
		{
			$this->EE->TMPL->log_item('Stash: MATCH '. $match . ' AGAINST ' . $part);
			
			if ( ! preg_match($match, $part))
			{
				$is_match = FALSE;
				break;
			}
		}
		return $is_match;
	}
	
	/**
	 * Retrieve and rebuild list, or optionally part of a list
	 *
	 * @access private
	 * @return string
	 */
	private function _rebuild_list()
	{
		$match 	 = $this->EE->TMPL->fetch_param('match', NULL); // regular expression to each list item against
		$against = $this->EE->TMPL->fetch_param('against', NULL); // array key to test $match against
		
		// run get() with a safe list of parameters
		$list = $this->_run_tag('get', array('name', 'type', 'scope', 'context'));

		if ($list !== '')
		{
			// trim and explode
			$list = trim($list, $this->_list_delimiter);
			$list = explode( $this->_list_delimiter, $list);
		
			foreach($list as $key => &$value)
			{
				$value = $this->_list_row_explode($value);
			}
		
			if ( ! is_null($match) && preg_match('/^#(.*)#$/', $match) && ! is_null($against))
			{
				$new_list = array();
				foreach($list as $key => $value)
				{
					if ( isset($value[$against]) )
					{
						if ($this->_matches($match, $value[$against]))
						{
							// match found
							$new_list[] = $value;
						}
					}
				}
				$list = $new_list;
			}
		}		
		return $list;
	}
	
	/**
	 * Retrieve {stash:var}{/stash:var} tag pairs and serialize
	 *
	 * @access private
	 * @return string
	 */
	private function _serialize_stash_tag_pairs()
	{
		//  get the stash var pairs values
		$stash_vars = array();		
	 
		foreach($this->EE->TMPL->var_pair as $key => $val)
		{
			if (strncmp($key, 'stash:', 6) ==  0)
			{	
				$pattern = '/'.LD.$key.RD.'(.*)'.LD.'\/'.$key.RD.'/Usi';
				preg_match($pattern, $this->EE->TMPL->tagdata, $matches);
				if (!empty($matches))
				{
					// don't set a key for an empty string, but preserve zeros 0
					if ( $this->not_empty($matches[1]) || $matches[1] === '0')
					{
						$stash_vars[substr($key, 6)] = preg_replace('/'.LD.'stash:[a-zA-Z0-9\-_]+'.RD.'(.*)'.LD.'\/stash:[a-zA-Z0-9\-_]+'.RD.'/Usi', '', $matches[1]);
					}
				}
			}
		}
	
		// flatten the array into a string
		$this->EE->TMPL->tagdata = $this->_list_row_implode($stash_vars);
	}
	
	// ---------------------------------------------------------
	
	/**
	 * @param array $array The array to implode
	 * @return string The imploded array
	 */	
	private function _list_row_implode($array) 
	{
		if ( ! is_array( $array ) ) return $array;
		$string = array();
    	foreach ( $array as $key => $val ) 
		{
        	if ( is_array( $val ) )
			{
            	$val = implode( ',', $val );
			}
        	$string[] = "{$key}{$this->_list_row_glue}{$val}";
    	}
    	return implode( $this->_list_row_delimiter, $string );
	}
	
	// ---------------------------------------------------------
	
	/**
	 * @param string $string The string to explode
	 * @return array The imploded array
	 */	
	private function _list_row_explode($string) 
	{
		$array = explode($this->_list_row_delimiter, $string);
		
		$new_array = array();
		
		foreach ( $array as $key => $val ) 
		{
			$val = explode($this->_list_row_glue, $val);
			
			if (isset($val[1]))
			{
				$new_array[$val[0]] = $val[1];
			}
		}	
		return $new_array;
	}
	
	// ---------------------------------------------------------
	
	/** 
	 * Sort a multi-dimensional array by key
	 *
	 * @access protected
	 * @param array Multidimensional array to sort
	 * @param string Array key to sort on
	 * @param string Callback function
	 * @return void
	 */
	protected function sort_by_key($arr, $key, $cmp='sort_by_integer') 
	{
	   	$this->_key2sort = $key;
		
	   	uasort($arr, array(__CLASS__, $cmp));
	   	return ($arr);
	}
	
	// ---------------------------------------------------------
	
	/** 
	 * Sort callback function: sort by string
	 *
	 * @access protected
	 * @param array
	 * @param array
	 */
	protected function sort_by_string($a, $b) 
	{
		return (strcasecmp($a[$this->_key2sort], $b[$this->_key2sort]));
	} 
	
	// ---------------------------------------------------------
	
	/** 
	 * Sort callback function: sort by integer
	 *
	 * @access protected
	 * @param array
	 * @param array
	 */
	protected function sort_by_integer($a, $b)
	{
	    if ($a[$this->_key2sort] == $b[$this->_key2sort]) 
		{
	        return 0;
	    }
	    return ($a[$this->_key2sort] < $b[$this->_key2sort]) ? -1 : 1;
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Replace the current context in a variable name
	 *
	 * @access private
	 * @param string	$name The variable name
	 * @return string
	 */
	private function _parse_context($name)
	{
		// replace '@' with current context
		if (strncmp($name, '@:', 2) ==  0)
		{
			$name = str_replace('@', self::$context, $name);
		}	
		return $name;
	}	
	
	// ---------------------------------------------------------
	
	/**
	 * Parse template data inside the stash tag pair
	 *
	 * @access private
	 * @param bool	$tags Parse plugin/module tags
	 * @param bool	$vars Parse globals (inc. snippets), native stash vars and segments
	 * @param bool	$conditionals Parse advanced conditionals
	 * @param int	$depth Number of passes to make of the template tagdata
	 * @return string
	 */
	private function _parse_sub_template($tags = TRUE, $vars = TRUE, $conditionals = FALSE, $depth = 1)
	{	
		$this->EE->TMPL->log_item("Stash: processing inner tags");
		
		$TMPL2 = $this->EE->TMPL;
		unset($this->EE->TMPL);

		// protect content inside {stash:nocache} tags
		$pattern = '/'.LD.'stash:nocache'.RD.'(.*)'.LD.'\/stash:nocache'.RD.'/Usi';
		$TMPL2->tagdata = preg_replace_callback($pattern, array(get_class($this), '_placeholders'), $TMPL2->tagdata);
		
		// parse variables	
		if ($vars)
		{	
			$TMPL2->tagdata = $this->_parse_template_vars($TMPL2->tagdata);
		}

		if ($tags)
		{
			// parse tags
			$this->EE->TMPL = new EE_Template();
			$this->EE->TMPL->start_microtime = $TMPL2->start_microtime;
			$this->EE->TMPL->template = $TMPL2->tagdata;
			$this->EE->TMPL->tag_data	= array();
			$this->EE->TMPL->var_single = array();
			$this->EE->TMPL->var_cond	= array();
			$this->EE->TMPL->var_pair	= array();
			$this->EE->TMPL->plugins = $TMPL2->plugins;
			$this->EE->TMPL->modules = $TMPL2->modules;
			$this->EE->TMPL->parse_tags();
			$this->EE->TMPL->process_tags();
			$this->EE->TMPL->loop_count = 0;
	
			$TMPL2->tagdata = $this->EE->TMPL->template;
			$TMPL2->log = array_merge($TMPL2->log, $this->EE->TMPL->log);

			foreach (get_object_vars($TMPL2) as $key => $value)
			{
				$this->EE->TMPL->$key = $value;
			}
		}
	
		// last template pass
		if ($depth == 1)
		{
			// parse advanced conditionals?
			if ($conditionals)
			{
				$TMPL2->tagdata = $TMPL2->advanced_conditionals($TMPL2->tagdata);
			}	
			
			// restore content inside {stash:nocache} tags
			foreach ($this->_ph as $index => $val)
			{
				$TMPL2->tagdata = str_replace('[_'.__CLASS__.'_'.($index+1).']', $val, $TMPL2->tagdata);
			}
		}
	
		$this->EE->TMPL = $TMPL2;	
		unset($TMPL2);
		
		// recursively parse?
		if ($depth > 1)
		{
			$depth --;
			
			// now the merry-go-round...
			// => parse the next shell of tags
			$this->_parse_sub_template($tags, $vars, $conditionals, $depth);
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Parse global vars inside a string
	 *
	 * @access private
	 * @param string	$template String to parse
	 * @return string
	 */
	private function _parse_template_vars($template = '')
	{	
		// globals vars {name}
		if (count($this->EE->config->_global_vars) > 0 && strpos($template, LD) !== FALSE)
		{
			foreach ($this->EE->config->_global_vars as $key => $val)
			{
				$template = str_replace(LD.$key.RD, $val, $template);
			}	
		}
		
		// stash vars {stash:var}
		if (count($this->EE->session->cache['stash']) > 0 && strpos($template, LD.'stash:') !== FALSE)
		{
			// We want to replace single stash tag not tag pairs such as {stash:var}whatever{/stash:var}
			// because these are used by stash::set() method when capturing multiple variables.
			// So we'll calculate the intersecting keys of existing stash vars and single tags in the template 
			// Note that the stash array goes first so that it's values are in the resultant array
			$tag_vars = $this->EE->functions->assign_variables($template);
			$tag_vars = $tag_vars['var_single'];
			$tag_vars = array_intersect_key($this->EE->session->cache['stash'], $tag_vars);
			
			foreach ($tag_vars as $key => $val)
			{
				// replace SINGLE tags, not tag pairs
				$template = str_replace(LD.'stash:'.$key.RD, $val, $template);
			}
		}
		
		// Parse date format string "constants"	
		if (strpos($template, LD.'DATE_') !== FALSE)
		{	
			$date_constants	= array('DATE_ATOM'		=>	'%Y-%m-%dT%H:%i:%s%Q',
									'DATE_COOKIE'	=>	'%l, %d-%M-%y %H:%i:%s UTC',
									'DATE_ISO8601'	=>	'%Y-%m-%dT%H:%i:%s%Q',
									'DATE_RFC822'	=>	'%D, %d %M %y %H:%i:%s %O',
									'DATE_RFC850'	=>	'%l, %d-%M-%y %H:%m:%i UTC',
									'DATE_RFC1036'	=>	'%D, %d %M %y %H:%i:%s %O',
									'DATE_RFC1123'	=>	'%D, %d %M %Y %H:%i:%s %O',
									'DATE_RFC2822'	=>	'%D, %d %M %Y %H:%i:%s %O',
									'DATE_RSS'		=>	'%D, %d %M %Y %H:%i:%s %O',
									'DATE_W3C'		=>	'%Y-%m-%dT%H:%i:%s%Q'
									);
			foreach ($date_constants as $key => $val)
			{
				$template = str_replace(LD.$key.RD, $val, $template);
			}
		}
		
		// Current time {current_time format="%Y %m %d %H:%i:%s"} - thanks @objectivehtml
		if (strpos($template, LD.'current_time') !== FALSE && preg_match_all("/".LD."current_time\s+format=([\"\'])([^\\1]*?)\\1".RD."/", $template, $matches))
		{				
			for ($j = 0; $j < count($matches[0]); $j++)
			{				
				$template = str_replace($matches[0][$j], $this->EE->localize->decode_date($matches[2][$j], $this->EE->localize->now), $template);	
			}
		}
		
		// segment vars {segment_1} etc
		if (strpos( $template, LD.'segment_' ) !== FALSE )
		{
			for ($i = 1; $i < 10; $i++)
			{
				$template = str_replace(LD.'segment_'.$i.RD, $this->EE->uri->segment($i), $template); 
			}
		}
		
		return $template;
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Final parsing of the stash variable before output to the template
	 *
	 * @access private
	 * @param string $value the string to parse	
	 * @param string $match A regular expression to match against
	 * @param string $filter A regular expression to filter by
	 * @param string $default fallback value
	 * @return string
	 */
	private function _parse_output($value = NULL, $match = NULL, $filter = NULL, $default = NULL)
	{
		// parse tags?
		if ( ($this->parse_tags || $this->parse_vars) && ! $this->parse_complete)
		{	
			$this->EE->TMPL->tagdata = $value;
			$this->_parse_sub_template($this->parse_tags, $this->parse_vars, $this->parse_conditionals, $this->parse_depth);
			$value = $this->EE->TMPL->tagdata;
			unset($this->EE->TMPL->tagdata);
		}
	
		// regex match
		if ( $match !== NULL && $value !== NULL )
		{	
			$is_match = $this->_matches($match, $value);

			if ( ! $is_match )
			{
				$value = $default;
			} 
		}
		
		// regex filter
		if ( $filter !== NULL && $value !== NULL)
		{
			preg_match($filter, $value, $found);
			if (isset($found[1]))
			{
				$value = $found[1];
			}
		}
		// apply string manipulations
		$value = $this->_clean_string($value);
		
		return $value;
	}
	
	// ---------------------------------------------------------
	/**
	 * String manipulations
	 *
	 * @access private
	 * @param string $value the string to parse	
	 * @return string
	 */
	private function _clean_string($value = NULL)
	{
		// register parameters
		$strip_tags = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('strip_tags'));	
		$strip_curly_braces = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('strip_curly_braces'));	
		$backspace = (int) $this->EE->TMPL->fetch_param('backspace', 0);
		$remove_unparsed_vars = (bool) preg_match('/1|on|yes|y/i', $this->EE->TMPL->fetch_param('remove_unparsed_vars'));

		// strip tags?
		if ($strip_tags)
		{
			$value = strip_tags($value);
		}

		// strip curly braces?
		if ($strip_curly_braces)
		{
			$value = str_replace(array(LD, RD), '', $value);
		}
	
		// backspace?
		if ($backspace)
		{
			$value = substr($value, 0, -$backspace);
		}
	
		// xss clean?
		if ($this->xss_clean)
		{
			$value = $this->EE->security->xss_clean($value);
		}
		
		// remove leftover placeholder variables {var} (leave stash: vars untouched)
		if ($remove_unparsed_vars)
		{
			$value = preg_replace('/\{\/?(?!\/?stash)[a-zA-Z0-9_\-:]+\}/', '', $value);
		}

		return $value;
	}
	
	// ---------------------------------------------------------
	/**
	 * Run a Stash module tag with a safe set of parameters
	 *
	 * @access private
	 * @param string $method the public Stash method to call	
	 * @param array $params the tag parameters to use
	 * @return string
	 */
	private function _run_tag($method, $params = array())
	{
		// make a copy of the original parameters
		$original_params = $this->EE->TMPL->tagparams;
		
		// array of permitted parameters
		$allowed_params = array_flip($params);
		
		// set permitted params for use
		foreach($allowed_params as $key => &$value)
		{
			if ( isset($this->EE->TMPL->tagparams[$key]))
			{
				$value = $this->EE->TMPL->tagparams[$key];
			}
			else
			{
				unset($allowed_params[$key]);
			}
		}
		
		// overwrite template params with our safe set
		$this->EE->TMPL->tagparams = $allowed_params;
		
		// run the tag if it is public
		if (method_exists($this, $method))
		{
		    $reflection = new ReflectionMethod($this, $method);
		    if ( ! $reflection->isPublic()) 
			{
				throw new RuntimeException("The called method is not public.");
			}
		    $out = $this->$method();
		}
		
		// restore original parameters
		$this->EE->TMPL->tagparams = $original_params;
		
		return $out;
	}	
	
	// ---------------------------------------------------------
	
	/** 
	 * _placeholders
	 *
	 * Replaces nested tag content with placeholders
	 *
	 * @access private
	 * @param array $matches
	 * @return string
	 */	
	private function _placeholders($matches)
	{
		$this->_ph[] = $matches[1];
		return '[_'.__CLASS__.'_'.count($this->_ph).']';
	}
	
}
/* End of file mod.stash.php */
/* Location: ./system/expressionengine/third_party/stash/mod.stash.php */