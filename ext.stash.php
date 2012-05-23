<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Set and get template variables, EE snippets and persistent variables.
 *
 * @package             Stash
 * @version				2.2.1
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2012 Hallmark Design
 * @license             http://creativecommons.org/licenses/by-nc-sa/3.0/
 * @link                http://hallmark-design.co.uk
 */

class Stash_ext {

	public $EE;
	public $name			= "Stash";
	public $version			= "2.2.1";
	public $description		= "Set and get template variables, EE snippets and persistent variables.";
	public $docs_url		= "http://hallmark-design.co.uk/code/stash/";
	public $settings 		= array();
	public $settings_exist	= 'n';
	private $hooks 			= array('template_fetch_template', 'template_post_parse');

	// ------------------------------------------------------

	/**
	 * Constructor
	 *
	 * @param 	mixed	Settings array or empty string if none exist
	 * @return void
	 */
	public function __construct($settings = array())
	{
		$this->EE =& get_instance();
		$this->settings = $settings;
	}

	// ------------------------------------------------------

	/**
	 * Activate Extension
	 * 
	 * @return void
	 */
	public function activate_extension()
	{
		foreach ($this->hooks AS $hook)
		{
			$this->_add_hook($hook);
		}
	}
	
	// ------------------------------------------------------

	/**
	 * Disable Extension
	 *
	 * @return void
	 */
	public function disable_extension()
	{
		$this->EE->db->where('class', __CLASS__);
		$this->EE->db->delete('extensions');
	}
	
	// ------------------------------------------------------

	/**
	 * Update Extension
	 *
	 * @param 	string	String value of current version
	 * @return 	mixed	void on update / FALSE if none
	 */
	public function update_extension($current = '')
	{
		if ($current == '' OR (version_compare($current, $this->version) === 0))
		{
			return FALSE; // up to date
		}

		// update table row with current version
		$this->EE->db->where('class', __CLASS__);
		$this->EE->db->update('extensions', array('version' => $this->version));
	}
	
	// --------------------------------------------------------------------

	/**
	 * Add extension hook
	 *
	 * @access     private
	 * @param      string
	 * @return     void
	 */
	private function _add_hook($name)
	{
		$this->EE->db->insert('extensions',
			array(
				'class'    => __CLASS__,
				'method'   => $name,
				'hook'     => $name,
				'settings' => '',
				'priority' => 10,
				'version'  => $this->version,
				'enabled'  => 'y'
			)
		);
	}
	
	// ------------------------------------------------------
	
	/**
	 * Method for template_fetch_template hook
	 *
	 * Inject early stash embeds into the template
	 *
	 * @access     public
	 * @param      array
	 * @return     array
	 */
	public function template_fetch_template($row)
	{
		// get the latest version of $row
		if (isset($this->EE->extensions->last_call) && $this->EE->extensions->last_call)
		{
			$row = $this->EE->extensions->last_call;
		}	
		
		// do we have any stash embeds?
		$matches = array();
		if ( ! preg_match_all("/(".LD."stash:embed)(\s.*?)".RD."/s", $row['template_data'], $matches))
		{
			return $row;
		}
		
		// deal with any unparsed {vars} inside parameters
		$temp = $row['template_data'];
		foreach ($matches[2] as $key => $val)
		{
			if (strpos($val, LD) !== FALSE)
			{
				$matches[0][$key] = $this->EE->functions->full_tag($matches[0][$key], $temp);
				$matches[2][$key] = substr(str_replace($matches[1][$key], '', $matches[0][$key]), 0, -1);
				$temp = str_replace($matches[0][$key], '', $temp);
			}
		}
		
		// match up embed params with tags
		$embeds = array();
	
		foreach($matches[2] as $key => $val)
		{
			$parts = preg_split("/\s+/", $val, 2);
		
			$embed_params = (isset($parts[1])) ? $this->EE->functions->assign_parameters($parts[1]) : array();
		
			if ($embed_params === FALSE)
			{
				$embed_params = array();
			}
	
			$embeds[trim($matches[0][$key], LD.RD)] = $embed_params;
		}

		if (count($embeds) > 0)
		{
			if ( ! class_exists('Stash'))
			{
				include_once PATH_THIRD . 'stash/mod.stash.php';
			}
			
			foreach($embeds as $tag => $param)
			{
				$out = '';
				
				if ( ! empty($param))
				{
					// parse early?
					if ( isset($param['process']) && $param['process'] == 'start')
					{
						// mandatory parameters
						$param['scope'] 			 = 'site';
						$param['file']  			 = 'yes';
						$param['save']  			 = 'yes';
						$param['process'] 			 = 'inline';
						
						// tags can't be parsed at this stage, so let's make sure of that
						$param['parse_tags'] 		 = 'no';
						$param['parse_vars'] 		 = 'no';
						$param['parse_conditionals'] = 'no';
						
						// get the file
						$out = Stash::get($param);
					}
					else
					{		
						// convert it into a normal tag...
						$out = LD.'exp:'.$tag.RD;
					}
				}
					
				// set as a global variable so it gets replaced into the template early by the Template class
				// $row is a copy not a reference, so this is the only way to change stuff in the actual template!
				$this->EE->config->_global_vars[$tag] = $out;
			}
		}

		return $row;
	}
	
	// ------------------------------------------------------

	/**
	 * Method for template_post_parse hook
	 *
	 * @param 	string	Parsed template string
	 * @param 	bool	Whether an embed or not
	 * @param 	integer	Site ID
	 * @return 	string	Template string
	 */
	public function template_post_parse($template, $sub, $site_id)
	{		
		// play nice with other extensions on this hook
		if (isset($this->EE->extensions->last_call) && $this->EE->extensions->last_call)
		{
			$template = $this->EE->extensions->last_call;
		}

		// is this the final template before output to browser?
		if ($sub !== FALSE)
		{
			// no
			if ( ! isset($this->EE->session->cache['stash']['__template_post_parse__']))
			{
				$this->EE->session->cache['stash']['__template_post_parse__'] = array();
			}
			$cache = $this->EE->session->cache['stash']['__template_post_parse__'];
			
			// cleanup
			unset($this->EE->session->cache['stash']['__template_post_parse__']);	
		}
		else
		{	
			// yes,this is the final template to be parsed (process="final")
			if ( ! isset($this->EE->session->cache['stash']['__template_post_parse_final__']))
			{
				$this->EE->session->cache['stash']['__template_post_parse_final__'] = array();
			}
			$cache = $this->EE->session->cache['stash']['__template_post_parse_final__'];
			
			// we need to concatenate the process="end" tag markers too 
			// if they haven't been parsed already in a parent template (i.e. there is only one template)
			if ( isset($this->EE->session->cache['stash']['__template_post_parse__']))
			{
				$cache += $this->EE->session->cache['stash']['__template_post_parse__'];
				unset($this->EE->session->cache['stash']['__template_post_parse__']);
			}
			
			// cleanup	
			unset($this->EE->session->cache['stash']['__template_post_parse_final__']);
		}
		
		// run any postponed stash tags
		if ( ! empty($cache))
		{		
			if ( ! class_exists('Stash'))
			{
				include_once PATH_THIRD . 'stash/mod.stash.php';
			}

			$s = new Stash();
		
			// save TMPL values for later
			$tagparams = $this->EE->TMPL->tagparams;
			$tagdata = $this->EE->TMPL->tagdata;
		
			// sort by priority
			$cache = $s->sort_by_key($cache, 'priority', 'sort_by_integer');

			// loop through, prep the Stash instance, call the postponed tag and replace output into the placeholder
			// if a placeholder isn't found, save it for a later pass by this extension
			$unparsed = array();
			foreach($cache as $placeholder => $tag)
			{	
				// make sure there is a placeholder in the template
				// it may have been removed by advanced conditional processing
				if ( strpos( $template, $placeholder ) !== FALSE)
				{
					$this->EE->TMPL->tagparams = $tag['tagparams'];
					$this->EE->TMPL->tagdata = $tag['tagdata'];
					
					$s->init(TRUE);
					
					$out = $s->{$tag['method']}();
					
					$template = str_replace(LD.$placeholder.RD, $out, $template);
				}
				else
				{
					$unparsed[$placeholder] = $tag;
				}
			}
			
			// do we have unparsed placeholders? Restore to cache for next time -
			// this shouldn't really be necessary, I need to investigate why EE doesn't
			// always run this hook at the exact point it's meant to
			if ( ! empty($unparsed) && $sub !== FALSE)
			{
				$this->EE->session->cache['stash']['__template_post_parse__'] = $unparsed;
			}
		
			// restore original TMPL values
			$this->EE->TMPL->tagparams = $tagparams;
			$this->EE->TMPL->tagdata = $tagdata;

			// cleanup
			unset($cache);
			unset($unparsed);
		}
		return $template;
	}
}

/* End of file ext.stash.php */ 
/* Location: ./system/expressionengine/third_party/stash/ext.stash.php */