<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Stash {

	public $EE;
	public $cache;
	public $refresh;
	
	private $_stash;

	/*
	 * Constructor
	 */
	public function __construct()
	{
		$this->EE = get_instance();
		
		// stash type, default to 'variable'
		$type = strtolower( $this->EE->TMPL->fetch_param('type', 'variable') );
		
		// determine the stash type
		if ($type === 'variable')
		{
			// we're setting/getting a variable
			if ( ! array_key_exists('stash', $this->EE->session->cache) )
			{
				// create a stash array in the session if we don't have one
				$this->EE->session->cache['stash'] = array();
			}
			$this->_stash =& $this->EE->session->cache['stash'];
		}
		elseif ($type === 'snippet')
		{
			// we're setting/getting a global {snippet}
			$this->_stash =& $this->EE->config->_global_vars;
		}
		else
		{
			$this->EE->output->show_user_error('general', $this->EE->lang->line('unknown_stash_type'));
		}	
		
		// get/set the variable value in the db?
		$this->cache = ( strtolower( $this->EE->TMPL->fetch_param('cache', 'no') ) == 'yes' ) ? TRUE : FALSE;
		$this->refresh = $this->EE->TMPL->fetch_param('refresh', 1440); // minutes (1440 = 1 day)
		
		// sanitize/filter retrieved variables? 
		// useful for user submitted data in superglobals - but don't do this by default!
		$this->strip_tags = (strtolower($this->EE->TMPL->fetch_param('strip_tags', 'no')) == 'yes') ? true : false;
		$this->strip_curly_braces = (strtolower($this->EE->TMPL->fetch_param('strip_curly_braces', 'no')) == 'yes') ? true : false;
	}

	// ---------------------------------------------------------
	
	/**
	 * Set content in the session (partial) or in the EE instance (snippet). 
	 * Optionally save to the database
	 *
	 * @access public
	 * @param bool 	 $update Update an existing stashed variable
	 * @param bool 	 $append Append or prepend to existing variable
	 * @return void 
	 */
	public function set($update = FALSE, $append = TRUE)
	{
		if ( !! $name = strtolower($this->EE->TMPL->fetch_param('name', FALSE)) )
		{	
			if ( $update === TRUE )
			{
				// Be graceful - turn the value into a string in case it's empty
				if ( empty($this->_stash[$name]) )
				{
					$this->_stash[$name] = '';
				}
			
				// Append or prepend?
				if ( $append )
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
			
			if ($this->cache)
			{
				$parameters = base64_encode( serialize( $this->EE->security->xss_clean($this->EE->TMPL->tagdata) ) );
				
				// delete any rows with the same key
				$this->EE->db->delete('stash', array('key' => $name));
				
				// insert new row, setting date in the future
				$this->EE->db->insert('stash', array(
					'key' => $this->EE->db->escape_str($name),
					'expire' => $this->EE->localize->now + ($this->refresh * 60),
					'parameters' => $parameters
				));
			}
		}
	}
	
	// ---------------------------------------------------------
	
	/**
	 * Get content from session, database cache or $_POST/$_GET superglobal
	 *
	 * @access public
	 * @return string 
	 */
	public function get()
	{
		$name = strtolower( $this->EE->TMPL->fetch_param('name') );
		$default = strtolower( $this->EE->TMPL->fetch_param('default', '') );
		$dynamic = (strtolower($this->EE->TMPL->fetch_param('dynamic', 'no')) == 'yes') ? TRUE : FALSE;
		$value = '';
		
		// Let's see if it's been stashed before
		if ( array_key_exists($name, $this->_stash) )
		{
			$value = $this->_stash[$name];
		}
		else
		{
			// Are we looking for a superglobal?
			if ( $dynamic )
			{
				if ( !! $this->EE->input->get_post($name) )
				{
					// get value from $_POST or $_GET, run through xss_clean()
					$value = $this->EE->input->get_post($name, TRUE);
					
					// save to stash, and optionally to database, if cache="yes"
					$this->EE->TMPL->tagparams['name'] = $name;
					$this->EE->TMPL->tagdata = $value;
					$this->set();
				}
			}	
			
			// Not found in globals, so let's look in the database table cache
			if (empty($value))
			{		
				// cleanup keys with date older than right now
				$this->EE->db->delete('stash', array('expire <' => $this->EE->localize->now));	
			
				// look for our key
				$query = $this->EE->db->select('parameters')
						->from('stash')
						->where('key', $this->EE->db->escape_str($name))
						->limit(1)
						->get();
			
				if ($query->num_rows())
				{
					if ($parameters = @unserialize(base64_decode($query->row('parameters'))))
					{
						// save to session 
						$value = $this->_stash[$name] = $parameters;
					}
				}
				else
				{
					$value = $default;
				}
			}
		}

		// strip tags?
		if ($this->strip_tags)
		{
			$value = strip_tags($value);
		}
		
		// strip curly braces?
		if ($this->strip_curly_braces)
		{
			$value = str_replace(array(LD, RD), '', $value);
		}
		
		return $value;
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
		$this->set(TRUE, TRUE);
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
		$this->set(TRUE, FALSE);
	}
}
/* End of file mod.stash.php */
/* Location: ./system/expressionengine/third_party/stash/mod.stash.php */