<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Set and get template variables, EE snippets and persistent variables.
 *
 * @package             Stash
 * @author              Mark Croxton (mcroxton@hallmark-design.co.uk)
 * @copyright           Copyright (c) 2012 Hallmark Design
 * @license             http://creativecommons.org/licenses/by-nc-sa/3.0/
 * @link                http://hallmark-design.co.uk
 */

class Stash_mcp {
    
    /**
     * Stash_mcp
     * 
     * @access  public
     * @return  void
     */
    public function __construct() 
    {
        $this->EE = get_instance();
    }
    
    /**
     * index
     * 
     * @access  public
     * @return  void
     */
    public function index()
    {
    }
}

/* End of file mcp.stash.php */
/* Location: ./system/expressionengine/third_party/stash/mcp.stash.php */