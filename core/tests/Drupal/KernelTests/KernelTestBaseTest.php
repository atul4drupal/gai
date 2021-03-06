<?php

/**
 * @file
 * Contains \Drupal\KernelTests\KernelTestBaseTest.
 */

namespace Drupal\KernelTests;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\visitor\vfsStreamStructureVisitor;

/**
 * @coversDefaultClass \Drupal\KernelTests\KernelTestBase
 * @group PHPUnit
 */
class KernelTestBaseTest extends KernelTestBase {

  /**
   * @covers ::setUpBeforeClass
   */
  public function testSetUpBeforeClass() {
    // Note: PHPUnit automatically restores the original working directory.
    $this->assertSame(realpath(__DIR__ . '/../../../../'), getcwd());
  }

  /**
   * @covers ::bootEnvironment
   */
  public function testBootEnvironment() {
    $this->assertRegExp('/^simpletest\d{6}$/', $this->databasePrefix);
    $this->assertStringStartsWith('vfs://root/sites/simpletest/', $this->siteDirectory);
    $this->assertEquals(array(
      'root' => array(
        'sites' => array(
          'simpletest' => array(
            substr($this->databasePrefix, 10) => array(
              'files' => array(
                'config' => array(
                  'active' => array(),
                  'staging' => array(),
                ),
              ),
            ),
          ),
        ),
      ),
    ), vfsStream::inspect(new vfsStreamStructureVisitor())->getStructure());
  }

  /**
   * @covers ::getDatabaseConnectionInfo
   */
  public function testGetDatabaseConnectionInfoWithOutManualSetDbUrl() {
    $this->setUp();

    $options = $this->container->get('database')->getConnectionOptions();
    $this->assertSame($this->databasePrefix, $options['prefix']['default']);
  }

  /**
   * @covers ::getDatabaseConnectionInfo
   */
  public function testGetDatabaseConnectionInfoWithManualSetDbUrl() {
    if (!file_exists('/tmp')) {
      $this->markTestSkipped();
    }
    putenv('SIMPLETEST_DB=sqlite://localhost//tmp/test2.sqlite');
    $this->setUp();

    $options = $this->container->get('database')->getConnectionOptions();
    $this->assertNotEqual('', $options['prefix']['default']);
  }

  /**
   * @covers ::setUp
   */
  public function testSetUp() {
    $this->assertTrue($this->container->has('request_stack'));
    $this->assertTrue($this->container->initialized('request_stack'));
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $this->assertNotEmpty($request);
    $this->assertEquals('/', $request->getPathInfo());

    $this->assertSame($request, \Drupal::request());

    $this->assertEquals($this, $GLOBALS['conf']['container_service_providers']['test']);

    $GLOBALS['destroy-me'] = TRUE;
    $this->assertArrayHasKey('destroy-me', $GLOBALS);

    $schema = $this->container->get('database')->schema();
    $schema->createTable('foo', array(
      'fields' => array(
        'number' => array(
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ),
      ),
    ));
    $this->assertTrue($schema->tableExists('foo'));
  }

  /**
   * @covers ::setUp
   * @depends testSetUp
   */
  public function testSetUpDoesNotLeak() {
    $this->assertArrayNotHasKey('destroy-me', $GLOBALS);

    // Ensure that we have a different database prefix.
    $schema = $this->container->get('database')->schema();
    $this->assertFalse($schema->tableExists('foo'));
  }

  /**
   * @covers ::register
   */
  public function testRegister() {
    // Verify that this container is identical to the actual container.
    $this->assertInstanceOf('Symfony\Component\DependencyInjection\ContainerInterface', $this->container);
    $this->assertSame($this->container, \Drupal::getContainer());

    // The request service should never exist.
    $this->assertFalse($this->container->has('request'));

    // Verify that there is a request stack.
    $request = $this->container->get('request_stack')->getCurrentRequest();
    $this->assertInstanceOf('Symfony\Component\HttpFoundation\Request', $request);
    $this->assertSame($request, \Drupal::request());

    // Trigger a container rebuild.
    $this->enableModules(array('system'));

    // Verify that this container is identical to the actual container.
    $this->assertInstanceOf('Symfony\Component\DependencyInjection\ContainerInterface', $this->container);
    $this->assertSame($this->container, \Drupal::getContainer());

    // The request service should never exist.
    $this->assertFalse($this->container->has('request'));

    // Verify that there is a request stack (and that it persisted).
    $new_request = $this->container->get('request_stack')->getCurrentRequest();
    $this->assertInstanceOf('Symfony\Component\HttpFoundation\Request', $new_request);
    $this->assertSame($new_request, \Drupal::request());
    $this->assertSame($request, $new_request);
  }

  /**
   * @covers ::getCompiledContainerBuilder
   *
   * The point of this test is to have integration level testing.
   */
  public function testCompiledContainer() {
    $this->enableModules(['system', 'user']);
    $this->assertNull($this->installConfig('user'));
  }

  /**
   * @covers ::getCompiledContainerBuilder
   * @depends testCompiledContainer
   *
   * The point of this test is to have integration level testing.
   */
  public function testCompiledContainerIsDestructed() {
    $this->enableModules(['system', 'user']);
    $this->assertNull($this->installConfig('user'));
  }

  /**
   * @covers ::render
   */
  public function testRender() {
    $type = 'processed_text';
    $element_info = $this->container->get('element_info');
    $this->assertSame(['#defaults_loaded' => TRUE], $element_info->getInfo($type));

    $this->enableModules(array('filter'));

    $this->assertNotSame($element_info, $this->container->get('element_info'));
    $this->assertNotEmpty($this->container->get('element_info')->getInfo($type));

    $build = array(
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => 'Inner',
    );
    $expected = "<h3>Inner</h3>\n";

    $this->assertEquals('core', \Drupal::theme()->getActiveTheme()->getName());
    $output = \Drupal::service('renderer')->renderRoot($build);
    $this->assertEquals('core', \Drupal::theme()->getActiveTheme()->getName());

    $this->assertEquals($expected, $build['#children']);
    $this->assertEquals($expected, $output);
  }

  /**
   * @covers ::render
   */
  public function testRenderWithTheme() {
    $this->enableModules(array('system'));

    $build = array(
      '#type' => 'textfield',
      '#name' => 'test',
    );
    $expected = '/' . preg_quote('<input type="text" name="test"', '/') . '/';

    $this->assertArrayNotHasKey('theme', $GLOBALS);
    $output = \Drupal::service('renderer')->renderRoot($build);
    $this->assertEquals('core', \Drupal::theme()->getActiveTheme()->getName());

    $this->assertRegExp($expected, (string) $build['#children']);
    $this->assertRegExp($expected, (string) $output);
  }

}
