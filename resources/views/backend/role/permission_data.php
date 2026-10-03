<?php

return [
    "import_batch" => [
        "import_batch-index" => "View Import / Landed Cost Batches",
        "import_batch-add" => "Create Import / Landed Cost Batch",
        "import_batch-edit" => "Edit Import / Landed Cost Batch",
        "import_batch-delete" => "Delete Import / Landed Cost Batch",
        "import_batch-landed-cost" => "Manage Landed Cost / Finalize / Reopen",
        "import_batch-profit-report" => "View Import Profitability",
    ],
    "commerce_module_access" => [
        "ecommerce" => \App\Services\ModuleRegistry::MODULES['ecommerce']['label'],
        "woocommerce" => \App\Services\ModuleRegistry::MODULES['woocommerce']['label'],
    ],
    "woocommerce_actions" => \App\Services\ModuleRegistry::MODULES['woocommerce']['permissions'],
    "sidebar_permissions" => [
        "sidebar_whatsapp" => "whatsapp",
    ],
    "mobile_app_module" => [
        "mobile_app" => "App Setting",
        "theme_settings" => "theme_settings",
    ],

    // Compatibility aliases. ModuleRegistry is the canonical source.
    "manufacturing_module" => \App\Services\ModuleRegistry::MODULES['manufacturing']['permissions'],
    "restaurant_module" => \App\Services\ModuleRegistry::MODULES['restaurant']['permissions'],
    "tailoring_module" => \App\Services\ModuleRegistry::MODULES['tailoring']['permissions'],
    "social_commerce_module" => \App\Services\ModuleRegistry::MODULES['socialcommerce']['permissions'],
    "project_module" => \App\Services\ModuleRegistry::MODULES['project']['permissions'],
    "repair_module" => \App\Services\ModuleRegistry::MODULES['repair']['permissions'],
    "ai_assistant_module" => \App\Services\ModuleRegistry::MODULES['aiassistant']['permissions'],
    "zatca_module" => \App\Services\ModuleRegistry::MODULES['zatcaintegrationksa']['permissions'],
    "vcardnfc_module" => \App\Services\ModuleRegistry::MODULES['vcardnfc']['permissions'],
    "indiagst_module" => \App\Services\ModuleRegistry::MODULES['indiagst']['permissions'],
    "gym_module" => \App\Services\ModuleRegistry::MODULES['gym']['permissions'],
];
