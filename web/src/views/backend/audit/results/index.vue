<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" title="工程质量审计 → 审计结果：每行 = 某项目某规则在一次审计轮次中的结果；行内「详情」查看问题明细，可筛选审计轮次/项目/规则/结果。" type="info" show-icon />

        <!-- 筛选条 -->
        <div class="audit-filters ba-table-alert">
            <el-select v-model="filterRunAt" placeholder="审计轮次" clearable style="width: 190px" @change="applyFilter">
                <el-option v-for="r in runOptions" :key="r" :label="fmtTs(r)" :value="String(r)" />
            </el-select>
            <el-select v-model="filterProject" placeholder="项目" clearable filterable style="width: 170px" @change="applyFilter">
                <el-option v-for="p in projectOptions" :key="p.id" :label="p.title + '（' + p.name + '）'" :value="String(p.id)" />
            </el-select>
            <el-select v-model="filterRule" placeholder="规则" clearable style="width: 170px" @change="applyFilter">
                <el-option v-for="r in ruleOptions" :key="r.name" :label="r.title" :value="r.name" />
            </el-select>
            <el-select v-model="filterPass" placeholder="结果" clearable style="width: 120px" @change="applyFilter">
                <el-option label="未通过" value="0" />
                <el-option label="通过" value="1" />
            </el-select>
            <el-button type="primary" plain @click="loadRuns">刷新轮次</el-button>
        </div>

        <!-- 表格顶部菜单 -->
        <TableHeader
            :buttons="['refresh', 'delete', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="t('Quick search placeholder', { fields: '项目/规则' })"
        />

        <!-- 表格 -->
        <Table ref="tableRef" />

        <!-- 问题明细弹窗 -->
        <el-dialog v-model="detailVisible" title="问题明细" width="820px" top="6vh">
            <div v-if="detailRow" class="detail-meta">
                <el-tag size="small" type="info">{{ detailRow.project_name }}</el-tag>
                <el-tag size="small" type="primary">{{ detailRow.rule_title }}</el-tag>
                <el-tag size="small" :type="Number(detailRow.is_pass) ? 'success' : 'danger'">
                    {{ Number(detailRow.is_pass) ? '通过' : '未通过（' + detailRow.issue_count + ' 个问题）' }}
                </el-tag>
                <el-tag size="small">{{ fmtTs(detailRow.run_at) }}</el-tag>
            </div>
            <el-scrollbar max-height="52vh">
                <ul v-if="detailIssues.length" class="detail-list">
                    <li v-for="(line, idx) in detailIssues" :key="idx" class="detail-line">{{ line }}</li>
                </ul>
                <el-empty v-else description="该规则无问题明细（通过/未执行）" :image-size="80" />
            </el-scrollbar>
            <template #footer>
                <el-button @click="detailVisible = false">关闭</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script setup lang="ts">
// 审计结果明细页（只读 baTable + 筛选条 + 详情弹窗；模板：ai/usage-log + asset 筛选模式）
import { onMounted, provide, ref, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { baTableApi } from '/@/api/common'
import TableHeader from '/@/components/table/header/index.vue'
import Table from '/@/components/table/index.vue'
import baTableClass from '/@/utils/baTable'
import createAxios from '/@/utils/axios'

defineOptions({
    name: 'audit/auditresult',
})

const { t } = useI18n()
const tableRef = useTemplateRef('tableRef')

const fmtTs = (ts: any) => {
    if (!ts) return '-'
    const n = Number(ts)
    if (!n || n <= 0) return '-'
    const d = new Date(n * 1000)
    const pad = (x: number) => String(x).padStart(2, '0')
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
}

const baTable: baTableClass = new baTableClass(
    new baTableApi('/admin/audit.AuditResult/'),
    {
        dblClickNotEditColumn: [undefined],
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', width: '70', operator: '=' },
            { label: '项目', prop: 'project_name', align: 'center', width: '130', operator: 'LIKE', showOverflowTooltip: true },
            {
                label: '规则',
                prop: 'rule_title',
                align: 'left',
                minWidth: '150',
                operator: 'LIKE',
                showOverflowTooltip: true,
                formatter: (row: anyObj) => row.rule_code + ' / ' + row.rule_title,
            },
            {
                label: '结果',
                prop: 'is_pass',
                align: 'center',
                width: '90',
                render: 'tag',
                custom: { '1': 'success', '0': 'danger' },
                replaceValue: { '1': '通过', '0': '未通过' },
            },
            {
                label: '问题数',
                prop: 'issue_count',
                align: 'center',
                width: '80',
                render: 'tag',
                custom: { '0': 'success' },
                formatter: (row: anyObj) => (Number(row.is_pass) ? '0' : row.issue_count),
            },
            { label: '审计时间', prop: 'run_at', align: 'center', width: '170', render: 'datetime' },
            {
                label: t('Operate'),
                align: 'center',
                width: '90',
                render: 'buttons',
                buttons: [
                    {
                        render: 'tipButton',
                        name: 'detail',
                        title: '详情',
                        text: '',
                        type: 'primary',
                        icon: 'fa fa-search-plus',
                        class: 'table-row-detail',
                        click: (row: anyObj) => {
                            openDetail(row)
                        },
                    },
                ],
                operator: false,
            },
        ],
    },
    {}
)

provide('baTable', baTable)

// ==================== 筛选条 ====================
const runOptions = ref<number[]>([])
const projectOptions = ref<anyObj[]>([])
const ruleOptions = ref<anyObj[]>([])
const filterRunAt = ref('')
const filterProject = ref('')
const filterRule = ref('')
const filterPass = ref('')

const applyFilter = () => {
    baTable.table.filter!.page = 1
    if (filterRunAt.value) baTable.table.filter!.run_at = filterRunAt.value
    else delete baTable.table.filter!.run_at
    if (filterProject.value) baTable.table.filter!.project_id = filterProject.value
    else delete baTable.table.filter!.project_id
    if (filterRule.value) baTable.table.filter!.rule_code = filterRule.value
    else delete baTable.table.filter!.rule_code
    if (filterPass.value !== '') baTable.table.filter!.is_pass = filterPass.value
    else delete baTable.table.filter!.is_pass
    baTable.getData()
}

// 轮次列表：默认选中最近一轮（latest 口径与后端 max(run_at) 一致）
const loadRuns = async () => {
    try {
        const res = await createAxios({ url: '/admin/audit.AuditResult/runs', method: 'get' })
        runOptions.value = res.data?.list || []
        if (runOptions.value.length) {
            filterRunAt.value = String(runOptions.value[0])
        }
        applyFilter()
    } catch {
        runOptions.value = []
    }
}

const loadOptions = async () => {
    try {
        const [pRes, rRes] = await Promise.all([
            createAxios({ url: '/admin/audit.AuditProject/index', method: 'get', params: { limit: 100 } }),
            createAxios({ url: '/admin/audit.AuditRule/index', method: 'get', params: { limit: 100 } }),
        ])
        projectOptions.value = pRes.data?.list || []
        ruleOptions.value = rRes.data?.list || []
    } catch {
        projectOptions.value = []
        ruleOptions.value = []
    }
}

// ==================== 详情 ====================
const detailVisible = ref(false)
const detailRow = ref<anyObj | null>(null)
const detailIssues = ref<string[]>([])

const openDetail = (row: anyObj) => {
    detailRow.value = row
    let issues: string[] = []
    try {
        const parsed = JSON.parse(row.detail || '[]')
        issues = Array.isArray(parsed) ? parsed.map((v: any) => String(v)) : []
    } catch {
        issues = []
    }
    detailIssues.value = issues
    detailVisible.value = true
}

onMounted(() => {
    baTable.table.ref = tableRef.value
    baTable.mount()
    loadOptions()
    loadRuns()
})
</script>

<style scoped>
.audit-filters {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    background: var(--ba-bg-color-overlay);
    border: 1px solid var(--ba-border-color);
    border-radius: 4px;
    padding: 10px 14px;
    margin-bottom: 12px;
}
.detail-meta {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.detail-list {
    margin: 0;
    padding: 0 0 0 18px;
}
.detail-line {
    font-size: 12px;
    line-height: 1.7;
    color: var(--el-text-color-regular);
    word-break: break-all;
    margin-bottom: 4px;
}
.table-row-detail {
    color: var(--el-color-primary) !important;
}
</style>