[![OJS compatibility](https://img.shields.io/badge/ojs-3.5-brightgreen)](https://github.com/pkp/ojs/tree/stable-3_5_0)
[![OMP compatibility](https://img.shields.io/badge/omp-3.5-brightgreen)](https://github.com/pkp/omp/tree/stable-3_5_0)
[![OPS compatibility](https://img.shields.io/badge/ops-3.5-brightgreen)](https://github.com/pkp/ops/tree/stable-3_5_0)
![GitHub release](https://img.shields.io/github/v/release/jonasraoni/fullTextSearch?include_prereleases&label=latest%20release&filter=v3*)
![GitHub code size in bytes](https://img.shields.io/github/languages/code-size/jonasraoni/fullTextSearch)
![License type](https://img.shields.io/github/license/jonasraoni/fullTextSearch)
![Number of downloads](https://img.shields.io/github/downloads/jonasraoni/fullTextSearch/total)

# Full Text Search Plugin

## About

Provides a replacement for the default search engine that comes with OJS/OMP/OPS, which performs very poorly on large datasets, it's backed by full-text index and it's compatible with MySQL and PostgreSQL. Search results are ranked by relevance score for better user experience.

This is a site-wide plugin, which means its settings are shared across all the journals/presses/servers of the installation.

## Installation Instructions

We recommend installing this plugin using the Plugin Gallery within OJS/OPS/OMP. Log in with administrator privileges, navigate to `Settings` > `Website` > `Plugins`, and choose the Plugin Gallery. Find the `Full Text Search Plugin` there and install it.

> If for some reason, you need to install it manually:
> - Download the latest release (attention to the OJS/OPS/OMP version compatibility) or from GitHub (attention to grab the code from the right branch).
> - Create the folder `plugins/generic/fullTextSearch` and place the plugin files in it.
> - Run the command `php lib/pkp/tools/installPluginVersion.php plugins/generic/fullTextSearch/version.xml` at the main OJS/OPS/OMP folder, this will ensure the plugin is installed/upgraded properly.

## Configuration

The plugin was coded to allow for a seamless and non-disruptive integration, so **it will not replace nor disable nor clear the standard search engine by default**, follow the instructions properly, you just need to it once and it will work for all journals/presses/servers.

Once enabled, the plugin will create a `full_text_search_plugin_index` table, where it will automatically start handling indexing of new submissions and updates to existing content, then you have to access its settings to setup the plugin.

**Instructions:**

1. Rebuild search index for all contexts. If you have a large installation, it's better to run the `php tools/rebuildSearchIndex.php --skip-standard-index` (the extra flag ensures that only the data for the plugin will be rebuilt) on the command-line instead, as it will not timeout.
2. Once it's over the indexing, enable the plugin to replace the standard search engine, by enabling the setting "[x] Use full text search".
3. Check if everything is working fine by searching for a keyword in the search box of the journal/press/server.
4. If everything is ok, you should enable the setting "[x] Disable standard indexing", which will stop feeding data to the standard search engine.
5. You can also clear the standard search tables on the last section of the settings. Besides making the database much smaller (if your installation is large, there will be millions of records on them), it will also improve the upgrade speed.

## Key Features

- **Comprehensive Indexing**: Indexes submission titles, abstracts, authors, keywords, subjects, disciplines, and full-text content
- **Advanced Search**: Full-text search with relevance scoring and ranking
- **Multi-Context Support**: Search across all journals/presses/servers or limit to specific contexts
- **Performance Optimized**: Database-backed search index for fast query execution
- **Flexible Configuration**: Customizable search settings and index rebuilding options
- **Cleanup Tools**: Automatic removal of unpublished content from search results

## Technical Details

- **Database Table**: Creates a dedicated `full_text_search_plugin_index` table
- **Search Engine**: Uses database-specific full-text search capabilities (MySQL MATCH/AGAINST, PostgreSQL tsvector/tsquery)
- **Indexing**: Automatically indexes published submissions and their associated files
- **Performance**: Optimized queries with relevance scoring and pagination support

## Contact/Support

If you have issues, please use the issue tracker (https://github.com/jonasraoni/fullTextSearch/issues).
