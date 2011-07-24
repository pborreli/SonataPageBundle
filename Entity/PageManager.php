<?php

/*
 * This file is part of the Sonata project.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\Entity;

use Sonata\PageBundle\Model\PageManagerInterface;
use Sonata\PageBundle\Model\PageInterface;
use Sonata\PageBundle\Model\BlockInterface;
use Sonata\PageBundle\Model\SnapshotInterface;
use Sonata\PageBundle\Model\Template;

use Application\Sonata\PageBundle\Entity\Page;
use Doctrine\ORM\EntityManager;

class PageManager implements PageManagerInterface
{
    protected $entityManager;

    protected $class;

    protected $templates = array();

    public function __construct(EntityManager $entityManager, $class = 'Application\Sonata\PageBundle\Entity\Page', $templates = array())
    {
        $this->entityManager = $entityManager;
        $this->class         = $class;
        $this->templates     = $templates;
    }

    /**
     * return a page with the given routeName
     *
     * @param string $routeName
     * @return PageInterface|false
     */
    public function getPageByName($routeName)
    {
        $pages = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from( $this->class, 'p')
            ->where('p.routeName = :routeName')
            ->setParameters(array(
                'routeName' => $routeName
            ))
            ->getQuery()
            ->execute();

        return count($pages) > 0 ? $pages[0] : false;
    }

    protected function getRepository()
    {
        return $this->entityManager->getRepository($this->class);
    }

    /**
     * return a page with the give slug
     *
     * @param string $url
     * @return PageInterface
     */
    public function getPageByUrl($url)
    {
        $pages = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from( $this->class, 'p')
            ->where('p.url = :url')
            ->setParameters(array(
                'url' => $url
            ))
            ->getQuery()
            ->execute();

        return count($pages) > 0 ? $pages[0] : false;
    }

    /**
     * @throws \RunTimeException
     * @return string
     */
    public function getDefaultTemplate()
    {
        foreach ($this->templates as $template) {
            if ($template->getDefault()) {
                return $template;
            }
        }

        throw new \RunTimeException('Please configure a default template in your configuration file');
    }

    public function createNewPage(array $defaults = array())
    {
        // create a new page for this routing
        $page = $this->getNewInstance();
        $page->setTemplate(isset($defaults['template']) ? $defaults['template'] : null);
        $page->setEnabled(isset($defaults['enabled']) ? $defaults['enabled'] : true);
        $page->setRouteName(isset($defaults['routeName']) ? $defaults['routeName'] : null);
        $page->setName(isset($defaults['name']) ? $defaults['name'] : null);
        $page->setSlug(isset($defaults['slug']) ? $defaults['slug'] : null);
        $page->setUrl(isset($defaults['url']) ? $defaults['url'] : null);
        $page->setCreatedAt(new \DateTime);
        $page->setUpdatedAt(new \DateTime);

        return $page;
    }

    public function fixUrl(PageInterface $page)
    {
        // hybrid page cannot be altered
        if (!$page->isHybrid()) {
            if (!$page->getSlug()) {
                $page->setSlug(Page::slugify($page->getName()));
            }

            // make sure Page has a valid url
            if ($page->getParent()) {
                $base = $page->getParent()->getUrl() == '/' ? '/' : $page->getParent()->getUrl().'/';
                $page->setUrl($base.$page->getSlug()) ;
            } else {
                $page->setUrl('/'.$page->getSlug());
            }
        }

        foreach ($page->getChildren() as $child) {
            $this->fixUrl($child);
        }
    }

    public function save(PageInterface $page)
    {
        if (!$page->isHybrid() || $page->getRouteName() == 'homepage') {
            $this->fixUrl($page);
        }

        $this->entityManager->persist($page);
        $this->entityManager->flush();

        return $page;
    }

    /**
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    public function getNewInstance()
    {
        $class = $this->getClass();

        return new $class;
    }

    public function findBy(array $criteria = array())
    {
        return $this->getRepository()->findBy($criteria);
    }

    public function findOneBy(array $criteria = array())
    {
        return $this->getRepository()->findOneBy($criteria);
    }

    public function loadPages()
    {
        $pages = $this->entityManager
            ->createQuery(sprintf('SELECT p FROM %s p INDEX BY p.id ORDER BY p.position ASC', $this->class))
            ->execute();

        foreach ($pages as $page) {
            $parent = $page->getParent();

            $page->disableChildrenLazyLoading();
            if (!$parent) {
                continue;
            }

            $pages[$parent->getId()]->disableChildrenLazyLoading();
            $pages[$parent->getId()]->addChildren($page);
        }

        return $pages;
    }

    public function getHybridPages()
    {
        return $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from( $this->class, 'p')
            ->where('p.routeName <> :routeName')
            ->setParameters(array(
                'routeName' => PageInterface::PAGE_ROUTE_CMS_NAME
            ))
            ->getQuery()
            ->execute();
    }

    public function setTemplates($templates)
    {
        $this->templates = $templates;
    }

    public function getTemplates()
    {
        return $this->templates;
    }

    public function addTemplate($code, Template $template)
    {
        $this->templates[$code] = $template;
    }

    public function getTemplate($code)
    {
        if (!isset($this->templates[$code])) {
            throw new \RunTimeException(sprintf('No template references whith the code : %s', $code));
        }

        return $this->templates[$code];
    }

    public function getClass()
    {
        return $this->class;
    }
}