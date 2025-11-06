# Test Fixtures

This directory contains test fixtures for EasyAudit CLI processors.

## Newly Added Processors

### Session 1 - XML Processors (2025-01-06 afternoon)

The following XML processors have been ported from easy-audit to easyaudit-cli:

### 1. Preferences Processor

**Location**: `src/Core/Scan/Processor/Preferences.php`

**Purpose**: Detects multiple preferences for the same interface/class across all di.xml files.

**Fixtures**:
- `Preferences/MultiplePreferences_di.xml` - Contains duplicate preferences (should trigger errors)
- `Preferences/SinglePreferences_di.xml` - Contains only unique preferences (should pass)

**Testing**:
```bash
# Test with bad file (should find 1 issue with 3 preferences for same interface)
./easyaudit.phar scan tests/fixtures/Preferences/MultiplePreferences_di.xml

# Test with good file (should find no issues)
./easyaudit.phar scan tests/fixtures/Preferences/SinglePreferences_di.xml
```

**What it detects**:
- Multiple preferences for `Magento\Customer\Api\CustomerRepositoryInterface`
- Reports all files that declare preferences for the same interface
- Helps prevent module load order issues

---

### 2. Cacheable Processor

**Location**: `src/Core/Scan/Processor/Cacheable.php`

**Purpose**: Detects blocks with `cacheable="false"` in layout XML files that shouldn't be non-cacheable.

**Fixtures**:
- `Cacheable/bad_cacheable.xml` - Contains blocks with cacheable="false" (should trigger warnings for non-allowed blocks)
- `Cacheable/good_cacheable.xml` - Contains properly cacheable blocks (should pass)

**Testing**:
```bash
# Test with bad file (should find 2 issues: product.list.block and custom.widget)
# Should NOT flag customer.info.block and sales.data.block (allowed areas)
./easyaudit.phar scan tests/fixtures/Cacheable/bad_cacheable.xml

# Test with good file (should find no issues)
./easyaudit.phar scan tests/fixtures/Cacheable/good_cacheable.xml
```

**What it detects**:
- Blocks with `cacheable="false"` that are not in allowed areas
- Allowed areas: customer, sales, gift, message (these are exempt)
- Level: `note` (suggestion for improvement)

---

### 3. AdvancedBlockVsViewModel Processor

**Location**: `src/Core/Scan/Processor/AdvancedBlockVsViewModel.php`

**Purpose**: Analyzes phtml templates for anti-patterns:
1. Use of `$this` instead of `$block`
2. Excessive data retrieval through blocks (should use ViewModels)

**Fixtures**:
- `AdvancedBlockVsViewModel/bad_use_of_this.phtml` - Uses `$this` instead of `$block` (should trigger errors)
- `AdvancedBlockVsViewModel/bad_data_crunch.phtml` - Excessive `$block->get*()` calls (should trigger warnings)
- `AdvancedBlockVsViewModel/good_with_viewmodel.phtml` - Properly uses ViewModel (should pass)
- `AdvancedBlockVsViewModel/good_minimal.phtml` - Minimal block usage with allowed methods (should pass)

**Testing**:
```bash
# Test bad $this usage (should find error)
./easyaudit.phar scan tests/fixtures/AdvancedBlockVsViewModel/bad_use_of_this.phtml

# Test data crunch (should find warning about 10+ method calls)
./easyaudit.phar scan tests/fixtures/AdvancedBlockVsViewModel/bad_data_crunch.phtml

# Test good ViewModel usage (should pass)
./easyaudit.phar scan tests/fixtures/AdvancedBlockVsViewModel/good_with_viewmodel.phtml

# Test minimal block usage (should pass)
./easyaudit.phar scan tests/fixtures/AdvancedBlockVsViewModel/good_minimal.phtml
```

**What it detects**:
- **Error**: Use of `$this->get*()` or `$this->is*()` in phtml files
- **Warning**: 3+ data retrieval calls without ViewModel usage
- **Allowed methods**: `getJsLayout`, `getChildHtml`, `escapeHtml`, `getUrl`, etc.

---

## Testing All New Processors

To test all fixtures at once:

```bash
# Test all fixtures in this directory
./easyaudit.phar scan tests/fixtures/ --exclude="vendor,node_modules"

# With SARIF output for GitHub Code Scanning
./easyaudit.phar scan tests/fixtures/ -f sarif -o results.sarif

# With JSON output
./easyaudit.phar scan tests/fixtures/ -f json -o results.json
```

## Scanner File Type Mapping

The scanner automatically maps file extensions to processor types:
- `*.xml` with `di.xml` suffix → `di` type (for Preferences, SameModulePlugins, etc.)
- Other `*.xml` files → `xml` type (for Cacheable and other layout processors)
- `*.phtml` → `phtml` type (for AdvancedBlockVsViewModel)
- `*.php` → `php` type (for AroundPlugins, UseOfRegistry, etc.)

**Note**: The Cacheable processor filters out di.xml files internally and only processes layout XML files.

## Expected Results

### Preferences
- **MultiplePreferences_di.xml**: Should detect 1 duplicate preference issue (3 prefs for same interface)
- **SinglePreferences_di.xml**: Should pass with no issues

### Cacheable
- **bad_cacheable.xml**: Should detect 2 blocks with problematic `cacheable="false"`
- **good_cacheable.xml**: Should pass with no issues

### AdvancedBlockVsViewModel
- **bad_use_of_this.phtml**: Should detect ~4 uses of `$this` (error)
- **bad_data_crunch.phtml**: Should detect excessive data crunch with 10+ calls (warning)
- **good_with_viewmodel.phtml**: Should pass (uses ViewModel)
- **good_minimal.phtml**: Should pass (only uses allowed methods)

---

### Session 2 - Logic Processors (2025-01-06 evening)

The following logic processors have been ported from easy-audit to easyaudit-cli:

### 4. BlockViewModelRatio Processor

**Location**: `src/Core/Scan/Processor/BlockViewModelRatio.php`

**Purpose**: Analyzes the ratio of Block classes to total classes per module to identify poor code organization.

**Fixtures**:
- `BlockViewModelRatio/HighRatio/` - Module with 80% blocks (4 blocks, 1 model) - should trigger warning
- `BlockViewModelRatio/GoodRatio/` - Module with 20% blocks (1 block, 2 viewmodels, 1 model, 1 helper) - should pass

**Testing**:
```bash
# Test with high block ratio (should find 1 issue)
./easyaudit.phar scan tests/fixtures/BlockViewModelRatio/HighRatio/

# Test with good ratio (should find no issues)
./easyaudit.phar scan tests/fixtures/BlockViewModelRatio/GoodRatio/

# Test both
./easyaudit.phar scan tests/fixtures/BlockViewModelRatio/
```

**What it detects**:
- Modules where more than 50% of classes are Blocks
- Reports module name, ratio, block count, and total count
- Suggests using ViewModels for better separation of concerns
- Level: `warning`

**Threshold**: Block ratio > 0.5 (50%)

---

### 5. UnusedModules Processor

**Location**: `src/Core/Scan/Processor/UnusedModules.php`

**Purpose**: Identifies modules present in codebase but disabled in app/etc/config.php.

**Fixtures**:
- `UnusedModules/app/code/Vendor/ActiveModule/etc/module.xml` - Active module (enabled in config.php)
- `UnusedModules/app/code/Vendor/DisabledModule/etc/module.xml` - Disabled module (should be flagged)
- `UnusedModules/app/code/Vendor/AnotherActive/etc/module.xml` - Active module
- `UnusedModules/app/etc/config.php` - Mock Magento config showing module states

**Testing**:
```bash
# Test from UnusedModules directory (will find config.php automatically)
cd tests/fixtures/UnusedModules
../../../easyaudit.phar scan .

# Or test from root
./easyaudit.phar scan tests/fixtures/UnusedModules/
```

**What it detects**:
- Modules with status `0` in app/etc/config.php
- Reports module name and path
- Suggests removing unused modules from codebase
- Level: `note` (suggestion)

**Important**: This processor requires access to `app/etc/config.php`. It will skip the check if the file cannot be found.

---

## Notes

- These processors follow the same pattern as existing processors (AroundPlugins, UseOfRegistry, etc.)
- All processors extend `AbstractProcessor` and implement the required methods
- Test fixtures are organized by processor name for easy maintenance
- The Scanner automatically discovers and runs all processors in `src/Core/Scan/Processor/`

---

### Session 3 - PHP/Code Quality & Helpers Processors (2025-01-06 evening)

The following PHP and Code Quality processors have been ported:

### 6. ProxyForHeavyClasses Processor

**Location**: `src/Core/Scan/Processor/ProxyForHeavyClasses.php`

**Purpose**: Checks if heavy classes (Session, Collection, ResourceModel) are injected without proxy configuration.

**Fixtures**:
- `ProxyForHeavyClasses/Bad/` - Customer class injects Session without proxy (should trigger error)
- `ProxyForHeavyClasses/Good/` - Customer class injects Session with proxy in di.xml (should pass)

**Testing**:
```bash
# Test bad (should find 1 issue - Session without proxy)
./easyaudit.phar scan tests/fixtures/ProxyForHeavyClasses/Bad/

# Test good (should pass - proxy configured)
./easyaudit.phar scan tests/fixtures/ProxyForHeavyClasses/Good/

# Test both
./easyaudit.phar scan tests/fixtures/ProxyForHeavyClasses/
```

**What it detects**:
- Classes injecting Session, Collection, or ResourceModel
- Checks if proxy is configured in di.xml
- Reports missing proxy configurations
- Level: `error`

**Heavy class patterns**: Session, Collection, ResourceModel

---

### 7. PaymentInterfaceUseAudit Processor

**Location**: `src/Core/Scan/Processor/PaymentInterfaceUseAudit.php`

**Purpose**: Detects payment methods extending deprecated AbstractMethod class.

**Fixtures**:
- `PaymentInterfaceUseAudit/Bad/DeprecatedPaymentMethod.php` - Extends AbstractMethod (should trigger error)
- `PaymentInterfaceUseAudit/Bad/AnotherBadPayment.php` - Also extends AbstractMethod with use statement
- `PaymentInterfaceUseAudit/Good/ModernPaymentMethod.php` - Implements PaymentMethodInterface (good)
- `PaymentInterfaceUseAudit/Good/PaymentAdapter.php` - Uses gateway pattern (good)

**Testing**:
```bash
# Test bad files (should find 2 issues)
./easyaudit.phar scan tests/fixtures/PaymentInterfaceUseAudit/Bad/

# Test good files (should pass)
./easyaudit.phar scan tests/fixtures/PaymentInterfaceUseAudit/Good/

# Test all
./easyaudit.phar scan tests/fixtures/PaymentInterfaceUseAudit/
```

**What it detects**:
- `extends \Magento\Payment\Model\Method\AbstractMethod`
- Both fully qualified and imported class names
- Level: `error`

---

### 8. Helpers Processor

**Location**: `src/Core/Scan/Processor/Helpers.php`

**Purpose**: Detects deprecated Helper patterns:
1. Helper classes extending AbstractHelper
2. Helpers used in phtml templates (should use ViewModels)

**Fixtures**:
- `Helpers/Bad/Helper/Data.php` - Extends AbstractHelper (deprecated)
- `Helpers/Bad/view/frontend/templates/product.phtml` - Uses helper in template
- `Helpers/Good/Helper/PriceUtility.php` - Simple utility without AbstractHelper
- `Helpers/Good/ViewModel/ProductDetails.php` - ViewModel for presentation logic
- `Helpers/Good/view/frontend/templates/product.phtml` - Uses ViewModel

**Testing**:
```bash
# Test bad (should find 2 issues: AbstractHelper + helper in phtml)
./easyaudit.phar scan tests/fixtures/Helpers/Bad/

# Test good (should pass)
./easyaudit.phar scan tests/fixtures/Helpers/Good/

# Test all
./easyaudit.phar scan tests/fixtures/Helpers/
```

**What it detects**:
- Classes extending `Magento\Framework\App\Helper\AbstractHelper`
- `$this->helper()` usage in phtml files
- Matches helpers to templates
- Two severity levels:
  - `error`: Helper extends AbstractHelper AND used in phtml
  - `warning`: Helper extends AbstractHelper but not in phtml

**Ignored helpers** (Magento core exceptions):
- Magento\\Customer\\Helper\\Address
- Magento\\Tax\\Helper\\Data
- Magento\\Msrp\\Helper\\Data
- Magento\\Catalog\\Helper\\Output
- Magento\\Directory\\Helper\\Data

---

## VendorDisabledModules Status

**NOT PORTED**: The `VendorDisabledModules` processor was analyzed but not ported because:
- It requires Magento runtime (StoreManager, ScopeConfig)
- It introspects Model/Config.php files and invokes methods at runtime
- It checks store-specific configuration values
- This is incompatible with the standalone CLI architecture

**Recommendation**: This processor would need a complete redesign to work in the CLI context, requiring architectural decisions about how to access runtime configuration.
