<?php

namespace Icinga\Module\Director\Web\Controller;

use Icinga\Application\Benchmark;
use Icinga\Application\Icinga;
use Icinga\Data\Filter\FilterChain;
use Icinga\Data\Filter\FilterExpression;
use Icinga\Exception\NotFoundError;
use Icinga\Data\Filter\Filter;
use Icinga\Module\Director\Db\Cache\PrefetchCache;
use Icinga\Module\Director\Forms\IcingaMultiEditForm;
use Icinga\Module\Director\Objects\IcingaObject;
use Icinga\Module\Director\Web\ActionBar\ObjectsActionBar;
use Icinga\Module\Director\Web\ActionBar\TemplateActionBar;
use Icinga\Module\Director\Web\Table\ApplyRulesTable;
use Icinga\Module\Director\Web\Table\ObjectSetTable;
use Icinga\Module\Director\Web\Table\ObjectsTable;
use Icinga\Module\Director\Web\Table\TemplatesTable;
use Icinga\Module\Director\Web\Tabs\ObjectsTabs;
use Icinga\Module\Director\Web\Tree\TemplateTreeRenderer;
use ipl\Html\Link;
use Zend_Db_Select as ZfSelect;

abstract class ObjectsController extends ActionController
{
    protected $isApified = true;

    /** @var ObjectsTable */
    protected $table;

    protected function checkDirectorPermissions()
    {
        $this->assertPermission('director/' . $this->getPluralBaseType());
    }
    /**
     * @return $this
     */
    protected function addObjectsTabs()
    {
        $tabName = $this->getRequest()->getActionName();
        if (substr($this->getType(), -5) === 'Group') {
            $tabName = 'groups';
        }
        $this->tabs(new ObjectsTabs($this->getBaseType(), $this->Auth()))
            ->activate($tabName);

        return $this;
    }

    public function indexAction()
    {
        if ($this->getRequest()->isApiRequest()) {
            try {
                $this->streamJsonResult();
            } catch (\Exception $e) {
                echo $e->getTraceAsString();
                exit;
            }
            return;
        }
        $type = $this->getType();
        $this
            ->addObjectsTabs()
            ->setAutorefreshInterval(10)
            ->addTitle($this->translate(ucfirst(strtolower($type)) . 's'))
            ->actions(new ObjectsActionBar($type, $this->url()));

        if ($type === 'command' && $this->params->get('type') === 'external_object') {
            $this->tabs()->activate('external');
        }

        // Hint: might be used in controllers extending this
        $this->table = $this->getTable();
        $this->table->renderTo($this);
    }

    protected function streamJsonResult()
    {
        $connection = $this->db();
        Benchmark::measure('aha');
        $db = $connection->getDbAdapter();
        $query = $this->getTable()
            ->getQuery()
            ->reset(ZfSelect::COLUMNS)
            ->columns('*')
            ->reset(ZfSelect::LIMIT_COUNT)
            ->reset(ZfSelect::LIMIT_OFFSET);

        echo '{ "objects": [ ';
        $cnt = 0;
        $objects = [];

        $dummy = IcingaObject::createByType($this->getType(), [], $connection);
        $dummy->prefetchAllRelatedTypes();

        Benchmark::measure('Prefetching');
        PrefetchCache::initialize($this->db());
        Benchmark::measure('Ready to query');
        $stmt = $db->query($query);
        $this->getResponse()->sendHeaders();
        if (! ob_get_level()) {
            ob_start();
        }
        $resolved = (bool) $this->params->get('resolved', false);
        $first = true;
        $flushes = 0;
        while ($row = $stmt->fetch()) {
            /** @var IcingaObject $object */
            if ($first) {
                Benchmark::measure('First row');
            }
            $object = $dummy::fromDbRow($row, $connection);
            $objects[] = json_encode($object->toPlainObject($resolved, true), JSON_PRETTY_PRINT);
            if ($first) {
                Benchmark::measure('Got first row');
                $first = false;
            }
            $cnt++;
            if ($cnt === 100) {
                if ($flushes > 0) {
                    echo ', ';
                }
                echo implode(', ', $objects);
                $cnt = 0;
                $objects = [];
                $flushes++;
                ob_end_flush();
                ob_start();
            }
        }

        if ($cnt > 0) {
            if ($flushes > 0) {
                echo ', ';
            }
            echo implode(', ', $objects);
        }

        if ($this->params->get('benchmark')) {
            echo "],\n";
            Benchmark::measure('All done');
            echo '"benchmark_string": ' . json_encode(Benchmark::renderToText());
        } else {
            echo '] ';
        }

        echo "}\n";
        if (ob_get_level()) {
            ob_end_flush();
        }

        // TODO: can we improve this?
        exit;
    }

    protected function getTable()
    {
        return ObjectsTable::create($this->getType(), $this->db())
            ->setAuth($this->getAuth());
    }

    public function editAction()
    {
        $type = ucfirst($this->getType());

        if (empty($this->multiEdit)) {
            throw new NotFoundError('Cannot edit multiple "%s" instances', $type);
        }

        $objects = $this->loadMultiObjectsFromParams();
        $formName = 'icinga' . $type;
        $form = IcingaMultiEditForm::load()
            ->setObjects($objects)
            ->pickElementsFrom($this->loadForm($formName), $this->multiEdit);
        if ($type === 'Service') {
            $form->setListUrl('director/services');
        } elseif ($type === 'Host') {
            $form->setListUrl('director/hosts');
        }

        $form->handleRequest();

        $this
            ->addSingleTab($this->translate('Multiple objects'))
            ->addTitle(
                $this->translate('Modify %d objects'),
                count($objects)
            )->content()->add($form);
    }

    /**
     * Loads the TemplatesTable or the TemplateTreeRenderer
     *
     * Passing render=tree switches to the tree view.
     */
    public function templatesAction()
    {
        $type = $this->getType();

        $shortType = IcingaObject::createByType($type)->getShortTableName();
        $this
            ->assertPermission('director/admin')
            ->addObjectsTabs()
            ->setAutorefreshInterval(10)
            ->addTitle(
                $this->translate('All your %s Templates'),
                $this->translate(ucfirst($type))
            )
            ->actions(new TemplateActionBar($shortType, $this->url()));

        $this->params->get('render') === 'tree'
            ? TemplateTreeRenderer::showType($shortType, $this, $this->db())
            : TemplatesTable::create($shortType, $this->db())->renderTo($this);
    }

    protected function assertApplyRulePermission()
    {
        return $this->assertPermission('director/admin');
    }

    public function applyrulesAction()
    {
        $type = $this->getType();
        $tType = $this->translate(ucfirst($type));
        $this
            ->assertApplyRulePermission()
            ->addObjectsTabs()
            ->setAutorefreshInterval(10)
            ->addTitle(
                $this->translate('All your %s Apply Rules'),
                $tType
            );
        $this->actions()/*->add(
            $this->getBackToDashboardLink()
        )*/->add(
            Link::create(
                $this->translate('Add'),
                "director/$type/add",
                ['type' => 'apply'],
                [
                    'title' => sprintf(
                        $this->translate('Create a new %s Apply Rule'),
                        $tType
                    ),
                    'class' => 'icon-plus',
                    'data-base-target' => '_next'
                ]
            )
        );

        $table = new ApplyRulesTable($this->db());
        $table->setType($this->getType());
        $table->renderTo($this);
    }

    public function setsAction()
    {
        $type = $this->getType();
        $tType = $this->translate(ucfirst($type));
        $this
            ->assertPermission('director/admin')
            ->addObjectsTabs()
            ->requireSupportFor('Sets')
            ->setAutorefreshInterval(10)
            ->addTitle(
                $this->translate('Icinga %s Sets'),
                $tType
            );

        $this->actions()/*->add(
            $this->getBackToDashboardLink()
        )*/->add(
            Link::create(
                $this->translate('Add'),
                "director/${type}set/add",
                null,
                [
                    'title' => sprintf(
                        $this->translate('Create a new %s Set'),
                        $tType
                    ),
                    'class' => 'icon-plus',
                    'data-base-target' => '_next'
                ]
            )
        );

        ObjectSetTable::create($type, $this->db())->renderTo($this);
    }

    protected function loadMultiObjectsFromParams()
    {
        $filter = Filter::fromQueryString($this->params->toString());
        $type = $this->getType();
        $objects = array();
        $db = $this->db();
        /** @var $filter FilterChain */
        foreach ($filter->filters() as $sub) {
            /** @var $sub FilterChain */
            foreach ($sub->filters() as $ex) {
                /** @var $ex FilterChain|FilterExpression */
                $col = $ex->getColumn();
                if ($ex->isExpression()) {
                    if ($col === 'name') {
                        $name = $ex->getExpression();
                        $objects[$name] = IcingaObject::loadByType($type, $name, $db);
                    } elseif ($col === 'id') {
                        $name = $ex->getExpression();
                        $objects[$name] = IcingaObject::loadByType($type, ['id' => $name], $db);
                    }
                }
            }
        }

        return $objects;
    }

    /**
     * @param $feature
     * @return $this
     * @throws NotFoundError
     */
    protected function requireSupportFor($feature)
    {
        if ($this->supports($feature) !== true) {
            throw new NotFoundError(
                '%s does not support %s',
                $this->getType(),
                $feature
            );
        }

        return $this;
    }

    protected function supports($feature)
    {
        $func = "supports$feature";
        return IcingaObject::createByType($this->getType())->$func();
    }

    protected function getBaseType()
    {
        $type = $this->getType();
        if (substr($type, -5) === 'Group') {
            return substr($type, 0, -5);
        } else {
            return $type;
        }
    }

    protected function getType()
    {
        // Strip final 's' and upcase an eventual 'group'
        return preg_replace(
            array('/group$/', '/period$/', '/argument$/', '/apiuser$/'),
            array('Group', 'Period', 'Argument', 'ApiUser'),
            str_replace(
                'template',
                '',
                substr($this->getRequest()->getControllerName(), 0, -1)
            )
        );
    }

    protected function getPluralType()
    {
        return $this->getType() . 's';
    }

    protected function getPluralBaseType()
    {
        return $this->getBaseType() . 's';
    }
}
