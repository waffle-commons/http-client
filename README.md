# Waffle Commons - Component Template
<img src="./images/waffle-commons_logo.png" alt="Logo Waffles Commons" style="width: 25%;" /><br />
This repository serves as a standardized template for creating new components within the Waffle Commons ecosystem. It provides a consistent structure, tooling configuration (Composer, PHPUnit, Mago, Psalm), and CI/CD pipeline (GitHub Actions) to accelerate development and maintain quality across all packages.

**Note:** Replace `YOUR_CODECOV_TOKEN_HERE` in the Codecov badge URL if you integrate Codecov. Also, replace `{COMPONENT_NAME}` placeholders in badges after running the configuration script or manually.

## Purpose
Using this template ensures that new components adhere to the established standards of the Waffle Commons project regarding:
- **Directory Structure:** Standard `src/`, `tests/`, etc.
- **Coding Standards:** Enforced via Mago (formatter, linter, analyzer) with pre-configured rules.
- **Testing:** Setup for PHPUnit, including configuration (`phpunit.xml`), bootstrap, and coverage reporting.
- **Static Analysis:** Configured for Psalm and Mago Analyze. 
- **Automation:** Pre-configured GitHub Actions workflow for CI, mirroring the core framework's quality checks. 
- **Documentation:** Standard files like this `CONTRIBUTING.md`, `LICENSE.md`, issue templates, etc. 
- **Composer Setup:** Pre-filled `composer.json` with necessary scripts and development dependencies.

## How to Use This Template
Follow these steps precisely to create a new Waffle Commons component:

### 1. **Clone the Template:**
Use this template to create a new `waffle-commons` repository.

### 2. **Run the Configuration Script:**
Execute the provided configuration script, passing the PascalCase component name as the first and only argument. This script will automatically replace the placeholder {COMPONENT_NAME} in file contents, filenames, and directory names.
```shell
# Example for 'Http' component
./configure-component.sh Http
```
- Carefully review the output of the script to ensure all replacements and renames were successful.

### 3. **Review and Finalize `composer.json`:**
- Open composer.json.
- Verify the `"name"` is correct (e.g., `waffle-commons/http`). It should have been updated by the script.
- Crucially, update the `"description"` field to accurately describe your new component's purpose. 
- Add any specific `require` dependencies needed for this component (e.g., `psr/http-message` for the `http` component).
- Add specific `require-dev` dependencies if needed beyond the standard template (e.g., `php-mock/php-mock-phpunit` was included, but others might be needed).
- Verify the PSR-4 namespaces in `autoload` and `autoload-dev` were correctly updated by the script.

### 4. **Updates in various files:**
- Edit this `README.md` file to describe the component.
- Edit `.github/workflows/main.yml` to activate it.

### 5. **Configure GitHub Repository Settings:**
- **Branch Protection:** Set up branch protection rules for `main` (require status checks to pass, require PR reviews, etc.).
- **Secrets:** Add necessary secrets (e.g., `CODECOV_TOKEN`) if applicable for CI workflows.
- **Labels:** Ensure standard labels (`bug`, `enhancement`, `good first issue`, etc.) are created (consider copying from `waffle-commons/waffle`).
- **Discussions:** Enable GitHub Discussions if desired for the component.
- **Issue:** Create customized template for **Bug report** (`.github/ISSUE_TEMPLATE/bug-report.md`) and  **Feature request** (`.github/ISSUE_TEMPLATE/feature-request.md`)
- **Pull request:** Ensure standard pull requests respect the template (`.github/PULL_REQUEST_TEMPLATE.md`)

### 6. **Start Developing!**
You can now start writing your component's code in the `src/` directory and corresponding tests in the `tests/` directory. Remember to follow the established coding standards.

## Development Tooling (Composer Scripts)
This template comes with pre-configured Composer scripts for common development tasks. Run these from the root of your new component's directory:
- **Install Dependencies:**
    ```shell
    composer install
    ```
- **Run Tests (PHPUnit):** Generates coverage reports in `var/data/phpunit-coverage/`.
    ```shell
    composer tests
    ```
- **Run Mago (Format Check, Lint, Analyze):**
    ```shell
    composer mago
    ```
    - Check Formatting Only: `composer formatter --check`
    - Apply Formatting: `composer formatter` 
    - Run Linter: `composer linter`
    - Run Analyzer: `composer analyzer`
- **Run Psalm-Taint Analysis:**
    ```shell
    vendor/bin/psalm --taint-analysis
    ```
- **Check for Dependency Vulnerabilities:**
    ```shell
    composer audit
    ```
- **Run All CI Checks Locally:** Simulates the checks run in GitHub Actions (without security checks).
    ```shell
    composer ci
    ```

## Contributing
While this repository is a template, contributions to the template itself (improving tooling, structure, CI) are welcome via Pull Requests to the `waffle-commons/component-template` repository.

For contributions to components created from this template, please refer to the main and the specific `CONTRIBUTING.md` within that component's repository.

## License
This template, and components created from it by default, are licensed under the MIT License. See the file for details.