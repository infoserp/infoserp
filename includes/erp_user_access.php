<?php
/**
 * ERP user company/branch access helpers.
 * Uses existing tables only: branch_user, branch_master, company_master.
 */

if (!function_exists('erp_load_user_company_branches')) {
    /**
     * Load companies and branches the user can access via branch_user rows.
     *
     * @return array{companies: list<array{company_code:string,company_name:string,branches:list<array{branch_code:string,branch_name:string}>}>}
     */
    function erp_load_user_company_branches(PDO $con, $user_id)
    {
        $user_id = trim((string) $user_id);
        if ($user_id === '') {
            return ['companies' => []];
        }

        $sql = "SELECT cm.company_code,
                       cm.company_name,
                       bm.branch_code,
                       bm.branch_name
                FROM   branch_user bu
                INNER JOIN branch_master bm
                        ON bm.branch_code = bu.branch_code
                INNER JOIN company_master cm
                        ON cm.company_code = bm.company_code
                WHERE  bu.user_id = :user_id
                ORDER BY cm.company_name, bm.branch_name";

        $stmt = $con->prepare($sql);
        $stmt->execute([':user_id' => $user_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $companyCode = trim((string) ($row['company_code'] ?? ''));
            $branchCode  = trim((string) ($row['branch_code'] ?? ''));
            if ($companyCode === '' || $branchCode === '') {
                continue;
            }

            if (!isset($grouped[$companyCode])) {
                $grouped[$companyCode] = [
                    'company_code' => $companyCode,
                    'company_name' => trim((string) ($row['company_name'] ?? '')),
                    'branches'     => [],
                ];
            }

            // Avoid duplicate branch rows if branch_user has duplicates.
            foreach ($grouped[$companyCode]['branches'] as $existing) {
                if ($existing['branch_code'] === $branchCode) {
                    continue 2;
                }
            }

            $grouped[$companyCode]['branches'][] = [
                'branch_code' => $branchCode,
                'branch_name' => trim((string) ($row['branch_name'] ?? '')),
            ];
        }

        return [
            'companies' => array_values($grouped),
        ];
    }
}

if (!function_exists('erp_user_can_access_branch')) {
    /**
     * Validate that user_id has a branch_user row for branch_code.
     * Returns the joined company/branch row on success, or null.
     *
     * @return array{company_code:string,company_name:string,branch_code:string,branch_name:string}|null
     */
    function erp_user_can_access_branch(PDO $con, $user_id, $branch_code)
    {
        $user_id     = trim((string) $user_id);
        $branch_code = trim((string) $branch_code);
        if ($user_id === '' || $branch_code === '') {
            return null;
        }

        $sql = "SELECT cm.company_code,
                       cm.company_name,
                       bm.branch_code,
                       bm.branch_name
                FROM   branch_user bu
                INNER JOIN branch_master bm
                        ON bm.branch_code = bu.branch_code
                INNER JOIN company_master cm
                        ON cm.company_code = bm.company_code
                WHERE  bu.user_id     = :user_id
                  AND  bu.branch_code = :branch_code";

        $stmt = $con->prepare($sql);
        $stmt->execute([
            ':user_id'     => $user_id,
            ':branch_code' => $branch_code,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'company_code' => trim((string) ($row['company_code'] ?? '')),
            'company_name' => trim((string) ($row['company_name'] ?? '')),
            'branch_code'  => trim((string) ($row['branch_code'] ?? '')),
            'branch_name'  => trim((string) ($row['branch_name'] ?? '')),
        ];
    }
}

if (!function_exists('erp_user_allow_zero_stock')) {
    /**
     * branch_user.allow_zero_stock for the session user/branch: 'Y' lets detail
     * lines take a Qty above the available Stock, anything else blocks it.
     * Cached in the session per branch so a branch change re-reads it.
     *
     * @return string 'Y' or 'N'
     */
    function erp_user_allow_zero_stock(PDO $con)
    {
        $user_id     = trim((string) ($_SESSION['username'] ?? ''));
        $branch_code = trim((string) ($_SESSION['branch_code'] ?? ''));
        if ($user_id === '' || $branch_code === '') {
            return 'N';
        }

        if (($_SESSION['allow_zero_stock_branch'] ?? null) === $branch_code
            && isset($_SESSION['allow_zero_stock'])) {
            return $_SESSION['allow_zero_stock'];
        }

        $flag = 'N';
        try {
            $stmt = $con->prepare("SELECT allow_zero_stock
                                   FROM   branch_user
                                   WHERE  branch_code = ?
                                     AND  user_id     = ?");
            $stmt->execute([$branch_code, $user_id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && strtoupper(trim((string) ($row['allow_zero_stock'] ?? ''))) === 'Y') {
                $flag = 'Y';
            }
        } catch (Exception $e) {
            error_log('erp_user_allow_zero_stock: ' . $e->getMessage());
        }

        $_SESSION['allow_zero_stock']        = $flag;
        $_SESSION['allow_zero_stock_branch'] = $branch_code;
        return $flag;
    }
}

if (!function_exists('erp_load_active_user_menus')) {
    /**
     * Return the user_menu rows for $menu_codes that are active AND whose whole
     * parent chain (up to 'Root') is active for this user/company/branch.
     *
     * user_menu.parent_code holds the parent's menu_name, not its menu_code.
     * When a parent name is shared by several menus and not pinned in
     * $parentOverrides, any one active candidate chain is enough.
     *
     * 'path' lists the ancestors from the top level down to the direct parent.
     *
     * @param list<string> $menu_codes
     * @return list<array{menu_code:string,menu_name:string,icon:string,sort_order:string,path:list<array{menu_code:string,menu_name:string,sort_order:string}>}>
     */
    function erp_load_active_user_menus(PDO $con, $company_code, $branch_code, $user_id, array $menu_codes)
    {
        $sql = "SELECT menu_code, menu_name, parent_code, active, icon, sort_order
                FROM   user_menu
                WHERE  company_code = :company_code
                  AND  branch_code  = :branch_code
                  AND  user_id      = :user_id";

        $stmt = $con->prepare($sql);
        $stmt->execute([
            ':company_code' => trim((string) $company_code),
            ':branch_code'  => trim((string) $branch_code),
            ':user_id'      => trim((string) $user_id),
        ]);

        $byCode      = [];
        $codesByName = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = trim((string) ($row['menu_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($row['menu_name'] ?? ''));
            $byCode[$code] = [
                'menu_code'   => $code,
                'menu_name'   => $name,
                'parent_code' => strtolower(trim((string) ($row['parent_code'] ?? ''))),
                'active'      => strtoupper(trim((string) ($row['active'] ?? ''))) === 'Y',
                'icon'        => trim((string) ($row['icon'] ?? '')),
                'sort_order'  => trim((string) ($row['sort_order'] ?? '')),
            ];
            $codesByName[strtolower($name)][] = $code;
        }

        // Menu names used by more than one menu_code; pins which one is the parent.
        $parentOverrides = [
            'inventory' => 'm_txn_inventory',
        ];

        // Returns the active ancestor path (top level first), or null when the chain is broken.
        $resolvePath = function ($code, $depth) use (&$resolvePath, $byCode, $codesByName, $parentOverrides) {
            if ($depth > 20 || !isset($byCode[$code]) || !$byCode[$code]['active']) {
                return null;
            }
            $parent = $byCode[$code]['parent_code'];
            if ($parent === '' || $parent === 'root') {
                return [];
            }
            $candidates = isset($parentOverrides[$parent])
                ? [$parentOverrides[$parent]]
                : ($codesByName[$parent] ?? []);
            foreach ($candidates as $parentCode) {
                $path = $resolvePath($parentCode, $depth + 1);
                if ($path !== null) {
                    $path[] = [
                        'menu_code'  => $parentCode,
                        'menu_name'  => $byCode[$parentCode]['menu_name'],
                        'sort_order' => $byCode[$parentCode]['sort_order'],
                    ];
                    return $path;
                }
            }
            return null;
        };

        $menus = [];
        foreach ($menu_codes as $code) {
            $path = $resolvePath($code, 0);
            if ($path !== null) {
                $menus[] = [
                    'menu_code'  => $byCode[$code]['menu_code'],
                    'menu_name'  => $byCode[$code]['menu_name'],
                    'icon'       => $byCode[$code]['icon'],
                    'sort_order' => $byCode[$code]['sort_order'],
                    'path'       => $path,
                ];
            }
        }

        return $menus;
    }
}
