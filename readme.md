StaticBuilder Plugin for Kirby CMS
==================================

- Converts your Kirby CMS site to static files: HTML, assets, etc.
- Supports multilingual websites.


How it works
------------

You have a Kirby-powered site. You can [add your content](https://getkirby.com/docs/content/adding-content) manually in the `content` folder or use the Panel. You can [use a theme](http://www.getkirby-themes.com/) or write your own templates and styles. You can preview your website using a local development server such as MAMP or WAMP.

Kirby StaticBuilder doesn’t change any of that. It gives you a basic HTML interface which enables you to build all your site’s pages and write the result in a folder named, you guessed it: `static`.

<img src="doc/html-ui.png" width="700" alt="">

Note: to get the right result for your site and needs, you may need to tweak some options.


Installation and usage
----------------------

StaticBuilder requires Kirby 2.3.

1.  [Download the latest release](https://github.com/fvsch/kirby-staticbuilder/releases/latest), rename the folder to `staticbuilder` and put it in `site/plugins`.<br>(Alternatively, you can install this plugin with the [Kirby CLI](https://github.com/getkirby/cli).)

2.  Enable the plugin: see the [config documentation](doc/config.md).<br>(It’s simple really, but we probably don’t want to enable it for a live website!)

3.  Load `http://localhost/staticbuilder` in a web browser (where `localhost` is the domain where you can see your Kirby site; it might be different depending on the test server you use or how you configured it).<br>
    You should see a list of pages. Check that these are indeed pages you want to export as HTML, and tweak the [options](doc/options.md) if needed.

4.  Hit the “Build” button. Hopefully things will work alright. If you have many pages (e.g. a few hundred), it might take a few seconds.

Note: every time you do a full build, the content of the `static` folder will be deleted. Don’t make changes there, or you will lose this work!


More docs
---------

-   [Best practices for static sites](doc/static.md)
-   [StaticBuilder options documentation](doc/options.md)


Running into bugs?
------------------

See the [list of issues](https://github.com/fvsch/kirby-staticbuilder/issues), and if nothing matches please create a new one (you will need a GitHub account).

**Known issues:**

1.	Building a lot of pages, or sometimes just a few pages, can be intensive. For instance if you’re making a lot of *thumbs* (resizing images in PHP) the script can time out; try visiting those pages first to build the thumbs, and start the static build after that.

2.  When building all pages, [each controller is only executed once](https://github.com/fvsch/kirby-staticbuilder/issues/9). This is a bug in Kirby core which should be fixed soon-ish.
