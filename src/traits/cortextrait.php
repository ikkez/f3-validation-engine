<?php

/**
 *  Cortex Mapper Validation Engine
 *
 *  The contents of this file are subject to the terms of the GNU General
 *  Public License Version 3.0. You may not use this file except in
 *  compliance with the license. Any of the license terms and conditions
 *  can be waived if you get permission from the copyright holder.
 *
 *  Copyright (c) 2019 by ikkez
 *  Christian Knuth <mail@ikkez.de>
 *
 *  @version 1.5.0
 *  @date 18.02.2019
 *  @since 08.03.2015
 *  @package Cortex
 */

namespace Validation\Traits;

trait CortexTrait {

	/**
	 * init mapper validation
	 * @param mixed $level
	 * @param string $op , operator to match against the level: <=, <, ==, >, >=
	 * @return bool
	 * @throws \Exception
	 */
	public function validate($level=0, $op='<=') {
		return \Validation::instance()->validateCortexMapper($this,$level,$op,true);
	}
	
}