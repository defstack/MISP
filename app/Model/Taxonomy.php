<?php
App::uses('AppModel', 'Model');

class Taxonomy extends AppModel {

	public $useTable = 'taxonomies';

	public $recursive = -1;

	public $actsAs = array(
			'Containable',
	);

	public $validate = array(
		'namespace' => array(
			'rule' => array('valueNotEmpty'),
		),
		'description' => array(
			'rule' => array('valueNotEmpty'),
		),
		'version' => array(
			'rule' => array('numeric'),
		)
	);

	public $hasMany = array(
			'TaxonomyPredicate' => array(
				'dependent' => true
			)
	);

	public function beforeValidate($options = array()) {
		parent::beforeValidate();
		return true;
	}

	public function update() {
		$directories = glob(APP . 'files' . DS . 'taxonomies' . DS . '*', GLOB_ONLYDIR);
		foreach ($directories as $k => &$dir) {
			$dir = str_replace(APP . 'files' . DS . 'taxonomies' . DS, '', $dir);
			if ($dir === 'tools') unset($directories[$k]);
		}
		$updated = array();
		foreach ($directories as &$dir) {
			$file = new File(APP . 'files' . DS . 'taxonomies' . DS . $dir . DS . 'machinetag.json');
			$vocab = json_decode($file->read(), true);
			$file->close();
			if (!isset($vocab['version'])) $vocab['version'] = 1;
			$current = $this->find('first', array(
				'conditions' => array('namespace' => $vocab['namespace']),
				'recursive' => -1,
				'fields' => array('version', 'enabled', 'namespace')
			));
			if (empty($current) || $vocab['version'] > $current['Taxonomy']['version']) {
				$result = $this->__updateVocab($vocab, $current, array('colour'));
				if (is_numeric($result)) {
					$updated['success'][$result] = array('namespace' => $vocab['namespace'], 'new' => $vocab['version']);
					if (!empty($current)) $updated['success'][$result]['old'] = $current['Taxonomy']['version'];
				} else {
					$updated['fails'][] = array('namespace' => $vocab['namespace'], 'fail' => json_encode($result));
				}
			}
		}
		return $updated;
	}

	private function __updateVocab(&$vocab, &$current, $skipUpdateFields = array()) {
		$enabled = 0;
		$taxonomy = array();
		if (!empty($current)) {
			if ($current['Taxonomy']['enabled']) $enabled = 1;
			$this->deleteAll(array('Taxonomy.namespace' => $current['Taxonomy']['namespace']));
		}
		$taxonomy['Taxonomy'] = array('namespace' => $vocab['namespace'], 'description' => $vocab['description'], 'version' => $vocab['version'], 'enabled' => $enabled);
		$predicateLookup = array();
		foreach ($vocab['predicates'] as $k => &$predicate) {
			$taxonomy['Taxonomy']['TaxonomyPredicate'][$k] = $predicate;
			$predicateLookup[$predicate['value']] = $k;
		}
		if (!empty($vocab['values'])) foreach ($vocab['values'] as &$value) {
			if (empty($taxonomy['Taxonomy']['TaxonomyPredicate'][$predicateLookup[$value['predicate']]]['TaxonomyEntry'])) {
				$taxonomy['Taxonomy']['TaxonomyPredicate'][$predicateLookup[$value['predicate']]]['TaxonomyEntry'] = $value['entry'];
			} else {
				$taxonomy['Taxonomy']['TaxonomyPredicate'][$predicateLookup[$value['predicate']]]['TaxonomyEntry'] = array_merge($taxonomy['Taxonomy']['TaxonomyPredicate'][$predicateLookup[$value['predicate']]]['TaxonomyEntry'], $value['entry']);
			}
		}
		$result = $this->saveAssociated($taxonomy, array('deep' => true));
		if ($result) {
			$this->__updateTags($this->id, $skipUpdateFields);
			return $this->id;
		}
		return $this->validationErrors;
	}

	private function __getTaxonomy($id, $options = array('full' => false, 'filter' => false)) {
		$recursive = -1;
		if ($options['full']) $recursive = 2;
		$filter = false;
		if (isset($options['filter'])) $filter = $options['filter'];
		$taxonomy = $this->find('first', array(
				'recursive' => $recursive,
				'conditions' => array('Taxonomy.id' => $id)
		));
		if (empty($taxonomy)) return false;
		$entries = array();
		foreach ($taxonomy['TaxonomyPredicate'] as &$predicate) {
			if (isset($predicate['TaxonomyEntry']) && !empty($predicate['TaxonomyEntry'])) {
				foreach ($predicate['TaxonomyEntry'] as &$entry) {
					$temp = array('tag' => $taxonomy['Taxonomy']['namespace'] . ':' . $predicate['value'] . '="' . $entry['value'] . '"');
					$temp['expanded'] = (!empty($predicate['expanded']) ? $predicate['expanded'] : $predicate['value']) . ': ' . (!empty($entry['expanded']) ? $entry['expanded'] : $entry['value']);
					$entries[] = $temp;
				}
			} else {
				$temp = array('tag' => $taxonomy['Taxonomy']['namespace'] . ':' . $predicate['value']);
				$temp['expanded'] = !empty($predicate['expanded']) ? $predicate['expanded'] : $predicate['value'];
				$entries[] = $temp;
			}
		}
		$taxonomy = array('Taxonomy' => $taxonomy['Taxonomy']);
		if ($filter) {
			$namespaceLength = strlen($taxonomy['Taxonomy']['namespace']);
			foreach ($entries as $k => &$entry) {
				if (strpos(substr(strtoupper($entry['tag']), $namespaceLength), strtoupper($filter)) === false) unset($entries[$k]);
			}
		}
		$taxonomy['entries'] = $entries;
		return $taxonomy;
	}

	// returns all tags associated to a taxonomy
	// returns all tags not associated to a taxonomy if $inverse is true
	public function getAllTaxonomyTags($inverse = false, $user = false) {
		$this->Tag = ClassRegistry::init('Tag');
		$taxonomyIdList = $this->find('list', array('fields' => array('id')));
		$taxonomyIdList = array_keys($taxonomyIdList);
		$allTaxonomyTags = array();
		foreach ($taxonomyIdList as &$taxonomy) {
			$allTaxonomyTags = array_merge($allTaxonomyTags, array_keys($this->getTaxonomyTags($taxonomy, true)));
		}
		$conditions = array();
		if ($user) {
			if (!$user['Role']['perm_site_admin']) {
				$conditions = array('Tag.org_id' => array(0, $user['org_id']));
			}
		}
		$allTags = $this->Tag->find(
			'list', array(
				'fields' => array('name'),
				'order' => array('UPPER(Tag.name) ASC'),
				'conditions' => $conditions
			)
		);
		foreach ($allTags as $k => &$tag) {
			if ($inverse && in_array(strtoupper($tag), $allTaxonomyTags)) unset($allTags[$k]);
			if (!$inverse && !in_array(strtoupper($tag), $allTaxonomyTags)) unset($allTags[$k]);
		}
		return $allTags;
	}

	public function getTaxonomyTags($id, $uc = false, $existingOnly = false) {
		$taxonomy = $this->__getTaxonomy($id, array('full' => true, 'filter' => false));
		if ($existingOnly) {
			$this->Tag = ClassRegistry::init('Tag');
			$tags = $this->Tag->find('list', array('fields' => array('name'), 'order' => array('UPPER(Tag.name) ASC')));
			foreach ($tags as &$tag) $tag = strtoupper($tag);
		}
		$entries = array();
		if ($taxonomy) {
			foreach ($taxonomy['entries'] as $k => &$entry) {
				$searchTerm = $uc ? strtoupper($entry['tag']) : $entry['tag'];
				if ($existingOnly) {
					if (in_array(strtoupper($entry['tag']), $tags)) {
						$entries[$searchTerm] = $entry['expanded'];
					}
					continue;
				}
				$entries[$searchTerm] = $entry['expanded'];
			}
		}
		return $entries;
	}

	public function getTaxonomy($id, $options = array('full' => true)) {
		$this->Tag = ClassRegistry::init('Tag');
		$taxonomy = $this->__getTaxonomy($id, $options);
		if (isset($options['full']) && $options['full']) {
			if (empty($taxonomy)) return false;
			$tags = $this->Tag->getTagsForNamespace($taxonomy['Taxonomy']['namespace']);
			if (isset($taxonomy['entries'])) {
				foreach ($taxonomy['entries'] as &$temp) {
					$temp['existing_tag'] = isset($tags[strtoupper($temp['tag'])]) ? $tags[strtoupper($temp['tag'])] : false;
				}
			}
		}
		return $taxonomy;
	}

	private function __updateTags($id, $skipUpdateFields = array()) {
		$this->Tag = ClassRegistry::init('Tag');
		App::uses('ColourPaletteTool', 'Tools');
		$paletteTool = new ColourPaletteTool();
		$taxonomy = $this->__getTaxonomy($id, array('full' => true));
		$colours = $paletteTool->generatePaletteFromString($taxonomy['Taxonomy']['namespace'], count($taxonomy['entries']));
		$this->Tag = ClassRegistry::init('Tag');
		$tags = $this->Tag->getTagsForNamespace($taxonomy['Taxonomy']['namespace']);
		foreach ($taxonomy['entries'] as $k => &$entry) {
			if (isset($tags[strtoupper($entry['tag'])])) {
				$temp = $tags[strtoupper($entry['tag'])];
				if ((in_array('colour', $skipUpdateFields) && $temp['Tag']['colour'] != $colours[$k]) || (in_array('name', $skipUpdateFields) && $temp['Tag']['name'] !== $entry['tag'])) {
					if (!in_array('colour', $skipUpdateFields)) $temp['Tag']['colour'] = $colours[$k];
					if (!in_array('name', $skipUpdateFields)) $temp['Tag']['name'] = $entry['tag'];
					$this->Tag->save($temp['Tag']);
				}
			}
		}
	}

	public function addTags($id, $tagList = false) {
		if ($tagList && !is_array($tagList)) $tagList = array($tagList);
		$this->Tag = ClassRegistry::init('Tag');
		App::uses('ColourPaletteTool', 'Tools');
		$paletteTool = new ColourPaletteTool();
		App::uses('ColourPaletteTool', 'Tools');
		$taxonomy = $this->__getTaxonomy($id, array('full' => true));
		$tags = $this->Tag->getTagsForNamespace($taxonomy['Taxonomy']['namespace']);
		$colours = $paletteTool->generatePaletteFromString($taxonomy['Taxonomy']['namespace'], count($taxonomy['entries']));
		foreach ($taxonomy['entries'] as $k => &$entry) {
			if ($tagList) {
				foreach ($tagList as $tagName) {
					if ($tagName === $entry['tag']) {
						if (isset($tags[strtoupper($entry['tag'])])) {
							$this->Tag->quickEdit($tags[strtoupper($entry['tag'])], $tagName, $colours[$k]);
						} else {
							$this->Tag->quickAdd($tagName, $colours[$k]);
						}
					}
				}
			} else {
				if (isset($tags[strtoupper($entry['tag'])])) {
					$this->Tag->quickEdit($tags[strtoupper($entry['tag'])], $entry['tag'], $colours[$k]);
				} else {
					$this->Tag->quickAdd($entry['tag'], $colours[$k]);
				}
			}
		}
		return true;
	}

	public function listTaxonomies($options = array('full' => false, 'enabled' => false)) {
		$recursive = -1;
		if (isset($options['full']) && $options['full']) $recursive = 2;
		$conditions = array();
		if (isset($options['enabled']) && $options['enabled']) $conditions[] = array('Taxonomy.enabled' => 1);
		$temp =  $this->find('all',  array(
			'recursive' => $recursive,
			'conditions' => $conditions
		));
		$taxonomies = array();
		foreach ($temp as &$t) {
			$taxonomies[$t['Taxonomy']['namespace']] = $t['Taxonomy'];
		}
		return $taxonomies;
	}
}
