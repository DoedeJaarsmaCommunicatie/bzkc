import CamelCase from './CamelCase';

class Router {
  /**
   * Create a new Router
   * @param {Object} routes
   */
  constructor(routes) {
    this.routes = routes;
  }

  /**
   * Fire Router events
   * @param {string} route DOM-based route derived from body classes (`<body class="...">`)
   * @param {string} [event] Events on the route. By default, `init` and `finalize` events are called.
   * @param {string} [arg] Any custom argument to be passed to the event.
   */
  fire(route, event = 'init', arg) {
    document.dispatchEvent(
      new CustomEvent('routed', {
        bubbles: true,
        detail: {
          route,
          fn: event,
        },
      })
    );

    Promise.resolve(this.routes[route]).then(res => {
      const fire = route !== '' && res && typeof res[event] === 'function';

      if (fire) {
        res[event](arg);
      }
    });
  }

  loadEvents() {
    this.fire('common');

    document.body.className
      .toLowerCase()
      .replace(/-/g, '_')
      .split(/\s+/)
      .map(CamelCase)
      .forEach(className => {
        this.fire(className);
        this.fire(className, 'finalize');
      });

    this.fire('common', 'finalize');
  }
}

export default Router;
