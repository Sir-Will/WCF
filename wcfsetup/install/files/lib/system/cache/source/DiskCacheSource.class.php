<?php
namespace wcf\system\cache\source;
use wcf\system\exception\SystemException;
use wcf\system\io\File;
use wcf\system\Callback;
use wcf\system\Regex;
use wcf\system\WCF;
use wcf\util\DirectoryUtil;
use wcf\util\FileUtil;

/**
 * DiskCacheSource is an implementation of CacheSource that stores the cache as simple files in the file system.
 * 
 * @author	Alexander Ebert, Marcel Werk
 * @copyright	2001-2013 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf
 * @subpackage	system.cache.source
 * @category	Community Framework
 */
class DiskCacheSource implements ICacheSource {
	/**
	 * @see	wcf\system\cache\source\ICacheSource::flush()
	 */
	public function flush($cacheName, $useWildcard) {
		if ($useWildcard) {
			$this->removeFiles('cache.'.$cacheName.'(-[a-f0-9]+)?.php');
		}
		else {
			$this->removeFiles('cache.'.$cacheName.'.php');
		}
	}
	
	/**
	 * @see	wcf\system\cache\source\ICacheSource::flushAll()
	 */
	public function flushAll() {
		DirectoryUtil::getInstance(WCF_DIR.'cache/')->removePattern(new Regex('.*\.php$'));
	}
	
	/**
	 * @see	wcf\system\cache\source\ICacheSource::get()
	 */
	public function get($cacheName, $maxLifetime) {
		$filename = $this->getFilename($cacheName);
		if ($this->needRebuild($filename, $maxLifetime)) {
			return null;
		}
		
		// load cache
		try {
			return $this->readCache($filename);
		}
		catch (\Exception $e) {
			return null;
		}
	}
	
	/**
	 * @see	wcf\system\cache\source\ICacheSource::set()
	 */
	public function set($cacheName, $value, $maxLifetime) {
		$file = new File($this->getFilename($cacheName));
		$file->write("<?php exit; /* cache: ".$cacheName." (generated at ".gmdate('r').") DO NOT EDIT THIS FILE */ ?>\n");
		$file->write(serialize($value));
		$file->close();
	}
	
	/**
	 * Returns cache filename.
	 * 
	 * @param	string		$cacheName
	 * @return	string
	 */
	protected function getFilename($cacheName) {
		return WCF_DIR.'cache/cache.'.$cacheName.'.php';
	}
	
	/**
	 * Removes files matching given pattern.
	 * 
	 * @param	string		$pattern
	 */
	protected function removeFiles($pattern) {
		$directory = FileUtil::unifyDirSeperator(WCF_DIR.'cache/');
		$pattern = str_replace('*', '.*', str_replace('.', '\.', $pattern));
		
		DirectoryUtil::getInstance($directory)->executeCallback(new Callback(function ($filename) {
			if (!@touch($filename, 1)) {
				@unlink($filename);
			}
		}), new Regex('^'.$directory.$pattern.'$', Regex::CASE_INSENSITIVE));
	}
	
	/**
	 * Determines wheater the cache needs to be rebuild or not.
	 * 
	 * @param	string		$filename
	 * @param	integer		$maxLifetime
	 * @return	boolean
	 */
	protected function needRebuild($filename, $maxLifetime) {
		// cache does not exist
		if (!file_exists($filename)) {
			return true;
		}
		
		// cache is empty
		if (!@filesize($filename)) {
			return true;
		}
		
		// cache resource was marked as obsolete
		if (($mtime = filemtime($filename)) <= 1) {
			return true;
		}
		
		// maxlifetime expired
		if ($maxLifetime > 0 && (TIME_NOW - $mtime) > $filename) {
			return true;
		}
		
		// do not rebuild cache
		return false;
	}
	
	/**
	 * Loads the file of a cached resource.
	 * 
	 * @param	string		$cacheName
	 * @param	string		$filename
	 * @return	mixed
	 */
	protected function readCache($cacheName, $filename) {
		// get file contents
		$contents = file_get_contents($filename);
		
		// find first newline
		$position = strpos($contents, "\n");
		if ($position === false) {
			throw new SystemException("Unable to load cache resource '".$cacheName."'");
		}
		
		// cut contents
		$contents = substr($contents, $position + 1);
		
		// unserialize
		$value = @unserialize($contents);
		if ($value === false) {
			throw new SystemException("Unable to load cache resource '".$cacheName."'");
		}
		
		return $value;
	}
}
