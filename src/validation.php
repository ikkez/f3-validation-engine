<?php

/**
 *  F3 Validation Engine
 *
 *  The contents of this file are subject to the terms of the GNU General
 *  Public License Version 3.0. You may not use this file except in
 *  compliance with the license. Any of the license terms and conditions
 *  can be waived if you get permission from the copyright holder.
 *
 *  Copyright (c) 2021 by ikkez
 *  Christian Knuth <mail@ikkez.de>
 *
 *  @version 1.7.0
 *  @date 24.01.2021
 *  @since 08.03.2015
 *  @package Cortex
 */

require 'gumpy.class.php';

class Validation extends \Prefab {

	protected $f3;
	protected $onerror;
	protected $aftervalidate;
	protected $lang_prefix;
	protected $models=[];
	protected $fallback_msg=[];
	protected $stringifyModel;

	/**
	 * Validation constructor.
	 * @throws Exception
	 */
	function __construct() {
		$this->f3 = \Base::instance();
		$this->lang_prefix = $this->f3->get('PREFIX');

		$this->setStringifyModel(function($mapper) {
			return strtolower(str_replace('\\','.',get_class($mapper)));
		});

		// register additional filters
		//////////////////////////////////

		$this->addFilter("website",function($value,$params=NULL) {
			if (empty($value))
				return NULL;
			if (!preg_match('/^\w+\:\/\/.*/i',$value,$match))
				$value='http://'.$value;
			return $value;
		});

		// register additional validators
		//////////////////////////////////

		$this->addValidator("empty", function($field, $input, $param=NULL) {
			return empty($input[$field]);
		},'The field "{0}" must be empty');

		$this->addValidator("notempty", function($field, $input, $param=NULL) {
			return $input[$field] !== 0 && $input[$field] !== 0.0 && $input[$field] !== '0' && !empty($input[$field]);
		},'The field "{0}" must not be empty');

		$this->addValidator('notnull', function($field,$input,$param=NULL) {
			return $input[$field] !== NULL;
		},'The field {0} is required');

		$this->addValidator("unique", function($field, $input, $param=NULL) {
			$model = $this->getModel();
			if (!$model)
				return true;
			$val = $input[$field];
			$valid = true;
			if (empty($val) || !$model->changed($field))
				return $valid;
			$filter = $model->dry()
				// new record
				? array($field.' = ?',$val)
				// change field of existing record, excludes itself
				: array($field.' = ? and _id != ?',$val,$model->_id);
			if ($model->findone($filter)) {
				$valid = false;
			}
			return $valid;
		},'The "{0}" is already taken');

		$this->addValidator("email_host", function($field, $input, $param=NULL) {
			$val = $input[$field];
			$valid = true;
			if (!empty($val)) {
				$mail_valid = \Audit::instance()->email($val,false);
				$host_valid = \Audit::instance()->email($val,true);
				if ($mail_valid && !$host_valid)
					$valid = false;
			}
			return $valid;
		},'Unknown mail mx-host');

	}

	/**
	 * add a filter
	 * @param string $rule
	 * @param callable|string $callback
	 */
	function addFilter($rule,$callback) {
		\GUMPy::add_filter($rule,function($value,$params=NULL) use ($callback) {
			return $this->f3->call($callback,[$value,$params]);
		});
	}

	/**
	 * add a validator
	 * @param string $rule
	 * @param callable|string $callback
	 * @param string $fallback_msg
	 */
	function addValidator($rule,$callback,$fallback_msg=null) {
		\GUMPy::add_validator($rule, function($field, $input, $param=NULL) use ($callback){
			return $this->f3->call($callback,[$field, $input, $param]);
		}, $fallback_msg);
		if ($fallback_msg)
			$this->fallback_msg[$rule]=$fallback_msg;
	}

	/**
	 * pass-through active mapper to be validated
	 * @return mixed|null
	 */
	function getModel() {
		return $this->models ? $this->models[count($this->models)-1] : NULL;
	}

	/**
	 * set new custom callable method to stringify the model name
	 * @return mixed|null
	 */
	function setStringifyModel($callback) {
		$this->stringifyModel = $callback;
	}

	/**
	 * init mapper validation
	 * @param \DB\Cortex $mapper
	 * @param mixed $level
	 * @param string $op , operator to match against the level: <=, <, ==, >, >=
	 * @param bool $alwaysApplyFilter, copy filtered fields to mapper, even if validation failed
	 * @return bool
	 * @throws Exception
	 */
	public function validateCortexMapper(\DB\Cortex $mapper, $level=0, $op='<=',$alwaysApplyFilter=false) {
		$level_cmp = ($op[0]=='<') ? -1 : (($op[0]=='>') ? 1 : 0);
		$level+=($op=='<=') ? 1 : (($op=='>=') ? -1 : 0);
		$valid = true;
		$context_error = call_user_func($this->stringifyModel, $mapper);
		$gump_conf = [
			'copy_fields' => [],
			'get_fields' => [],
			'filter' => [],
			'rules' => [],
			'post_filter' => []
		];
		// TODO: only check changed fields
		$fieldConf = $mapper->getFieldConfiguration();
		foreach($fieldConf as $field=>$conf) {
			// memorize probably changed fields
			if (isset($conf['filter']) || isset($conf['post_filter']))
				$gump_conf['copy_fields'][$field] = true;
			// incoming filter
			if (isset($conf['filter']))
				$gump_conf['filter'][$field] = $conf['filter'];

			// skip fields that doesn't match validation level
			if (isset($conf['validate_level']) &&
				!($this->f3->sign(($conf['validate_level']-$level))==$level_cmp))
				continue;
			// mark relation field to be fully fetched later
			if (isset($conf['relType'])) {
				$gump_conf['get_fields'][$field]=$conf;
			}

			$validate=true;
			// check if the validation has dependencies
			if (!empty($conf['validate_depends'])) {
				foreach ($conf['validate_depends'] as $key=>$rule) {
					$ref = $mapper->get($key);
					$skip=false;
					if (is_array($rule)) {
						switch ($rule[0]) {
							case 'call':
								$skip=!\Base::instance()->call($rule[1],
									[$mapper->get($field),$mapper]);
								break;
							case 'validate':
								$skip=!GUMPy::is_valid([$key=>$ref],[$key=>$rule[1]]);
								break;
						}
					} else
						// simple value comparison
						$skip = ($ref !== $rule);
					if ($skip) {
						$validate=false;
						break;
					}
				}
			}
			$context = $context_error.'.'.$field;
			// configurate GUMP
			// ===================
			if ($validate && (isset($conf['item']) ||
				isset($conf['validate_array']) ||
				isset($conf['validate_nested_array'])))
			{
				$val = $mapper->get($field);

				// move contains check
				if (isset($conf['item']) && !empty($val))
					$conf['validate'] = (!empty($conf['validate'])?$conf['validate'].'|':'').
						'contains, \''.implode("';'",(is_string($conf['item'])
							?$this->f3->{$conf['item']}:$conf['item'])).'\'';
				// validate array field
				if (isset($conf['validate_array']) && !empty($val)) {
					$valid_array = $this->validate($conf['validate_array'],$val,$context);
					$mapper->set($field,$val);
					$gump_conf['copy_fields'][$field] = true;
					if (!$valid_array)
						$valid = false;
				}
				// validate nested array elements
				if (isset($conf['validate_nested_array']) && !empty($val)) {
					$checks=[];
					foreach ($val as $key=>&$field_data) {
						$checks[] = $this->validate($conf['validate_nested_array'],
							$field_data,[$context.'.'.$key,$context]);
						unset($field_data);
					}
					$mapper->set($field,$val);
					$gump_conf['copy_fields'][$field] = true;
					foreach ($checks as $test)
						if (!$test)
							$valid = false;
				}
			}
			// validation rules
			if ($validate && isset($conf['validate']))
				$gump_conf['rules'][$field] = $conf['validate'];
			// outgoing filter
			if (isset($conf['post_filter'])) {
				$gump_conf['copy_fields'][$field] = true;
				$gump_conf['post_filter'][$field] = $conf['post_filter'];
			}
		}
		// run GUMP
		if ($gump_conf['filter'] || $gump_conf['rules'] || $gump_conf['post_filter']) {
			$validator = \GUMPy::get_instance();
			$data = $mapper->cast(null,0);
			// lazy-load relational data
			foreach ($gump_conf['get_fields'] as $field => $conf) {
				$data[$field] = $mapper->get($field);
			}
			if ($gump_conf['filter'])
				$data = $validator->filter($data, $gump_conf['filter']);
			if ($gump_conf['rules']) {
				$this->models[] = $mapper;
				$validated = $validator->validate($data, $gump_conf['rules']);
				array_pop($this->models);
				if ($validated !== true) {
					$valid = false;
					foreach ($validated as $err) {
						$err['rule'] = str_replace('validate_','',$err['rule']);
						$context = $context_error.'.'.$err['field'];
						// provide translated error messages
						$errText=$this->renderErrorText([$err['field'],$err['param']],$err['rule'],$context);
						if (!$errText)
							$errText = $err['field'];
						if ($this->onerror)
							$this->f3->call($this->onerror,
								array($errText,$context.'.'.$err['rule']));
						unset($errText);
					}
				}
			}
			if ($gump_conf['post_filter'])
				$data = $validator->filter($data, $gump_conf['post_filter']);
		}
		if ($this->aftervalidate)
			$valid = $this->f3->call($this->aftervalidate,array($mapper,$this));

		// only set filtered fields
		if (($valid || $alwaysApplyFilter) && !empty($gump_conf['copy_fields']) && isset($data))
			$mapper->copyfrom($data,array_keys($gump_conf['copy_fields']));
		return $valid;
	}

	/**
	 * simple array validation
	 * @param array $rules
	 * @param array $data
	 * @param string|null $context
	 * @param int $level
	 * @param string $level_op
	 * @return bool
	 * @throws Exception
	 */
	public function validate($rules, &$data, $context=NULL, $level=0, $level_op='<=') {
		$level_cmp = ($level_op[0]=='<') ? -1 : (($level_op[0]=='>') ? 1 : 0);
		$level+=($level_op=='<=') ? 1 : (($level_op=='>=') ? -1 : 0);
		$valid = true;
		$gump_conf = [
			'filter' => [],
			'rules' => [],
			'post_filter' => []
		];
		if (!$context) {
			$context_error = NULL;
			$context_label = NULL;
		} else {
			if (!is_array($context))
				$context = [$context,$context];
			list($context_error,$context_label) = $context;
		}
		foreach($rules as $field=>$conf) {
			// incoming filter
			if (isset($conf['filter']))
				$gump_conf['filter'][$field] = $conf['filter'];

			// skip fields that doesn't match validation level
			if (isset($conf['validate_level']) &&
				!($this->f3->sign(($conf['validate_level']-$level))==$level_cmp))
				continue;

			$val = &$data[$field];

			$validate=true;
			if (!empty($conf['validate_depends'])) {
				foreach ($conf['validate_depends'] as $key=>$rule) {
					$ref = $data[$key];
					$skip=false;
					if (is_array($rule)) {
						switch ($rule[0]) {
							case 'call':
								$skip=!\Base::instance()->call($rule[1],[$val,$data]);
								break;
							case 'validate':
								$skip=!\GUMPy::is_valid([$key=>$ref],[$key=>$rule[1]]);
								break;
						}
					} else
						// simple value comparison
						$skip = ($ref !== $rule);
					if ($skip) {
						$validate=false;
						break;
					}
				}
			}
			// configurate GUMP
			// ===================
			if ($validate && (isset($conf['item']) ||
					isset($conf['validate_array']) ||
					isset($conf['validate_nested_array'])))
			{
				// move contains check
				if (isset($conf['item']) && !empty($val))
					$conf['validate'] = (!empty($conf['validate'])?$conf['validate'].'|':'').
						'contains, \''.implode("';'",is_string($conf['item'])
							?$this->f3->{$conf['item']}:$conf['item']).'\'';
				// validate array field
				if (isset($conf['validate_array']) && !empty($val)) {
					if ($context)
						$context = [$context_error.'.'.$field, $context_label.'.'.$field];
					$valid_array = $this->validate($conf['validate_array'],$val,$context,$level,$level_op);
					if (!$valid_array)
						$valid = false;
				}
				// validate nested array elements
				if (isset($conf['validate_nested_array']) && !empty($val)) {
					$checks=[];
					foreach ($val as $key=>&$field_data) {
						if ($context)
							$context = [$context_error.'.'.$field.'.'.$key, $context_label.'.'.$field.'.'.$key];
						$checks[] = $this->validate($conf['validate_nested_array'],
							$field_data,$context,$level,$level_op);
						unset($field_data);
					}
					foreach ($checks as $test)
						if (!$test)
							$valid = false;
				}
			}
			// validation rules
			if ($validate && isset($conf['validate']))
				$gump_conf['rules'][$field] = $conf['validate'];
			// outgoing filter
			if (isset($conf['post_filter'])) {
				$gump_conf['post_filter'][$field] = $conf['post_filter'];
			}
		}
		// run GUMP
		if ($gump_conf['filter'] || $gump_conf['rules'] || $gump_conf['post_filter']) {
			$validator = \GUMPy::get_instance();

			if ($gump_conf['filter'])
				$data = $validator->filter($data, $gump_conf['filter']);
			if ($gump_conf['rules']) {
				$validated = $validator->validate($data, $gump_conf['rules']);
				if ($validated !== true) {
					$valid = false;
					foreach ($validated as $err) {
						$err['rule'] = str_replace('validate_','',$err['rule']);
						if ($context)
							$context = [$context_error.'.'.$err['field'], $context_label.'.'.$err['field']];
						// provide translated error messages
						$errText=$this->renderErrorText([$err['field'],$err['param']],$err['rule'],$context);
						if (!$errText)
							$errText = $err['field'];
						if ($this->onerror)
							$this->f3->call($this->onerror,
								array($errText,($context?$context_error.'.':'').$err['field'].'.'.$err['rule']));
						unset($errText);
					}
				}
			}
			if ($gump_conf['post_filter'])
				$data = $validator->filter($data, $gump_conf['post_filter']);
		}
		return $valid;
	}

	/**
	 * register on error handler
	 * @param $callback ($msg, $context)
	 */
	public function onError($callback) {
		$this->onerror = $callback;
	}

	/**
	 * register after validate handler
	 * @param $callback ($msg, $context)
	 */
	public function afterValidate($callback) {
		$this->aftervalidate = $callback;
	}

	/**
	 * try to assemble error label in this order:
	 *
	 * HIVE: [{PREFIX}error.{context}.{type}]
	 * HIVE [{PREFIX}error.validation.{type}]
	 * fallback
	 * context.type
	 *
	 * field name is formatted into the label at {0} when possible
	 *
	 * @param string|array $field (or array with [field,params])
	 * @param string $type the validation rule type
	 * @param string|array $context
	 * @param null $fallback
	 * @return null|string
	 */
	function renderErrorText($field,$type,$context=null,$fallback=NULL) {
		if (!$context)
			$context = 'validation';
		$context_label=$context;
		if (is_array($context))
			list($context,$context_label) = $context;
		if (!$this->f3->exists($this->lang_prefix.'error.'.$context.'.'.$type,$errText) &&
			!$this->f3->exists($this->lang_prefix.'error.'.$context_label.'.'.$type,$errText) &&
			!$this->f3->exists($this->lang_prefix.'error.validation.'.$type,$errText)) {
			if ($fallback)
				$errText = $fallback;
			elseif (isset($this->fallback_msg[$type]))
				$errText = $this->fallback_msg[$type];
			else return $context.'.'.$type;
		}
		$params=NULL;
		if (is_array($field))
			list($field,$params) = $field;
		if (!$context ||
			(!$this->f3->exists($this->lang_prefix.$context_label.'.label',$fieldLabel) &&
				!$this->f3->exists($this->lang_prefix.'model.base.'.$field.'.label',$fieldLabel)))
			$fieldLabel = ucfirst($field);
		$errText = $this->f3->format($errText,preg_replace('/-_/','',$fieldLabel),$this->f3->stringify($params));
		return $errText;
	}

	/**
	 * send a single validation error to the onError handler
	 * @param string|array $field fieldName or array [fieldName, [params] ]
	 * @param $type
	 * @param null $context
	 * @param null $fallback
	 */
	function emitError($field,$type,$context=null,$fallback=NULL) {
		$errText = $this->renderErrorText($field,$type,$context,$fallback);
		list($context_error,$context_label) = is_array($context) ? $context : [$context,$context];
		// provide translated error messages
		if (is_array($field))
			$field=$field[0];
		if ($errText && $this->onerror)
			$this->f3->call($this->onerror,
				array($errText,($context?$context_error.'.':'').$field.'.'.$type));
	}

	/**
	 * load language dictionary
	 * @param int $ttl
	 * @param string $path
	 */
	function loadLang($ttl=0,$path='lang/') {
		$dir = $this->f3->fixslashes(__DIR__);
		$lex=$this->f3->lexicon(realpath($dir.'/'.$path).'/,'.
			realpath($dir.'/'.$path.'ext/').'/',$ttl);
		if ($lex)
			foreach ($lex as $dt=>$dd) {
				$ref=&$this->f3->ref($this->lang_prefix.$dt);
				$ref=$dd;
				unset($ref);
			}
	}

}