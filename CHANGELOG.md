# Changelog

All notable changes to this project will be documented in this file.

This project follows the principles of [Keep a Changelog](https://keepachangelog.com/en/1.1.0).

## [Unreleased]

### Added

- Initial database package structure.
- PSR-4 autoloading with the `Hkianim\Database` namespace.
- `DataSourceInterface` for defining database read sources.
- `TableSource` for reading from database tables.
- `ViewSource` for reading from database views.
- `QuerySource` for reading from custom SQL queries with positional bindings.
- `FilterCompiler` with nested `AND` and `OR` groups.
- Support for comparison, membership, NULL, and LIKE filter operators.
- `QueryBuilder` for generating SELECT and count queries.
- `QueryExecutor` for executing prepared statements through PDO.
- `BaseRepository` with common CRUD operations.
- Support for single-column and composite primary keys.
- Support for independent read and write sources.
- Pagination, sorting, total count, and generated row numbers.
- Custom repository exceptions.
- Initial automated tests for data sources, filters, query building, query execution, and repository operations.
- Initial README documentation.

### Security

- Prepared statement parameter binding for query values.
- Validation of available columns and SQL identifiers.
- Validation of filter structures and write data.

### Compatibility

- PHP 7.4 and later.
- MySQL and MariaDB through PDO.

---

[Unreleased]: https://github.com/USERNAME/REPOSITORY/compare/v0.0.0...HEAD