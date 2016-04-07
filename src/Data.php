<?php
namespace simpleframe;

use js\tools\commons\traits\DataAccessor;
use JsonSerializable;

class Data implements JsonSerializable
{
	use DataAccessor;
	
	public function __construct(array $data)
	{
		$this->init($data);
	}
	
	public function jsonSerialize()
	{
		return $this->getAll();
	}
}
