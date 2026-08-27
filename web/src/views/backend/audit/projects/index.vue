<template>
    <div class="default-main ba-table-box">
        <el-alert class="ba-table-alert" title="工程质量审计 → 审计项目：项目 = 工作区 src 根下的一个包目录；点「运行审计」对本轮启用的项目执行全部启用规则，结果落库并回填最近一轮快照（问题总数/未通过规则）。运行审计为同步操作，项目较多时约需几秒。" type="info" show-icon />

        <!-- 顶部统计条 -->
        <div v-if="stats" class="audit-stats ba-table-alert">
            <el-tag size="small" type="info">项目 {{ stats.project_total }}（启用 {{ stats.project_enabled }}）</el-tag>
            <el-tag size="small" type="info">规则 {{ stats.rule_total }}（启用 {{ stats.rule_enabled }}）</el-tag>
            <template v-if="stats.last_run_at">
                <el-tag size="small" type="primary">最近审计 {{ fmtTs(stats.last_run_at) }}</el-tag>
                <el-tag size="small" :type="stats.last_issue_total > 0 ? 'danger' : 'success'">问题 {{ stats.last_issue_total }}</el-tag>
                <el-tag size="small" type="success">通过 {{ stats.last_pass_projects }} 个项目</el-tag>
                <el-tag size="small" :type="stats.last_fail_projects > 0 ? 'danger' : 'info'">未通过 {{ stats.last_fail_projects }} 个项目</el-tag>
            </template>
            <el-tag v-else size="small" type="warning">尚未运行过审计</el-tag>
        </div>

        <!-- 表格顶部菜单（运行审计按钮挂在默认插槽） -->
        <TableHeader
            :buttons="['refresh', 'add', 'delete', 'quickSearch', 'columnDisplay']"
            :quick-search-placeholder="t('Quick search placeholder', { fields: '包名/项目名' })"
        >
            <el-button v-blur :loading="running" type="success" class="table-header-audit-run" @click="runAudit([])">
                <Icon name="fa fa-search" />
                <span class="table-header-operate-text">运行全部审计</span>
            </el-button>
        </TableHeader>

        <!-- 表格 -->
        <Table ref="tableRef" />

        <!-- 表单 -->
        <PopupForm ref="formRef" />
    </div>
</template>

<script setup lang="ts">
// 审计项目管理页（baTable CRUD + 运行审计；模板：agent/list + agent/test/runs 行按钮 + ai/channel stats）
import { onMounted, provide, ref, useTemplateRef } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import PopupForm from './popupForm.vue'
import { baTableApi } from '/@/api/common'
import { defaultOptButtons } from '/@/components/table'
import TableHeader from '/@/components/table/header/index.vue'
import Table from '/@/components/table/index.vue'
import baTableClass from '/@/utils/baTable'
import createAxios from '/@/utils/axios'

defineOptions({
    name: 'audit/auditproject',
})

const { t } = useI18n()
const formRef = useTemplateRef('formRef')
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
    new baTableApi('/admin/audit.AuditProject/'),
    {
        dblClickNotEditColumn: [undefined],
        column: [
            { type: 'selection', align: 'center', operator: false },
            { label: 'ID', prop: 'id', align: 'center', width: '70', operator: '=' },
            { label: '包目录名', prop: 'name', align: 'center', width: '150', operator: 'LIKE', showOverflowTooltip: true },
            { label: '项目名称', prop: 'title', align: 'left', minWidth: '160', operator: 'LIKE', showOverflowTooltip: true },
            {
                label: '状态',
                prop: 'status',
                align: 'center',
                width: '80',
                render: 'tag',
                custom: { enabled: 'success', disabled: 'info' },
                replaceValue: { enabled: '启用', disabled: '停用' },
            },
            {
                label: '最近审计',
                prop: 'last_run_at',
                align: 'center',
                width: '170',
                formatter: (row: anyObj) => fmtTs(row.last_run_at),
            },
            {
                label: '问题总数',
                prop: 'last_issue_count',
                align: 'center',
                width: '90',
                render: 'tag',
                custom: { 0: 'success' },
                formatter: (row: anyObj) => (row.last_run_at ? row.last_issue_count : '-'),
            },
            {
                label: '未通过规则',
                prop: 'last_fail_rules_arr',
                align: 'left',
                minWidth: '200',
                showOverflowTooltip: true,
                formatter: (row: anyObj) => (row.last_run_at ? (row.last_fail_rules_arr && row.last_fail_rules_arr.length ? row.last_fail_rules_arr.join('、') : '-') : '-'),
            },
            { label: '排序', prop: 'weigh', align: 'center', width: '70' },
            {
                label: t('Operate'),
                align: 'center',
                width: '300',
                render: 'buttons',
                buttons: [
                    ...defaultOptButtons(['edit']),
                    {
                        render: 'tipButton',
                        name: 'run',
                        title: '运行审计',
                        text: '',
                        type: 'success',
                        icon: 'fa fa-search',
                        class: 'table-row-run',
                        click: (row: anyObj) => {
                            runAudit([row.id])
                        },
                    },
                    {
                        render: 'tipButton',
                        name: 'switch',
                        title: '启停',
                        text: '',
                        type: 'warning',
                        icon: 'fa fa-power-off',
                        class: 'table-row-switch',
                        click: (row: anyObj) => {
                            toggleStatus(row)
                        },
                    },
                    {
                        render: 'confirmButton',
                        name: 'delete',
                        title: '删除',
                        text: '',
                        type: 'danger',
                        icon: 'fa fa-trash',
                        class: 'table-row-delete',
                        popconfirm: {
                            confirmButtonText: '删除',
                            cancelButtonText: '取消',
                            confirmButtonType: 'danger',
                            title: '确认删除该项目？结果明细将一并删除。',
                        },
                        disabledTip: false,
                    },
                ],
                operator: false,
            },
        ],
    },
    {
        defaultItems: {
            name: '',
            title: '',
            status: 'enabled',
            weigh: 0,
            remark: '',
        },
    }
)

provide('baTable', baTable)

const toggleStatus = (row: anyObj) => {
    const status = row.status === 'enabled' ? 'disabled' : 'enabled'
    ElMessageBox.confirm('确认' + (status === 'enabled' ? '启用' : '停用') + '项目「' + row.title + '」？', '提示', { type: 'warning' })
        .then(async () => {
            await createAxios({ url: '/admin/audit.AuditProject/switch', method: 'post', data: { id: row.id, status } })
            ElMessage.success('操作成功')
            baTable.onTableHeaderAction('refresh')
        })
        .catch(() => {})
}

// ==================== 运行审计 ====================
const running = ref(false)

const runAudit = async (ids: number[]) => {
    if (running.value) return
    running.value = true
    try {
        const res = await createAxios({ url: '/admin/audit.AuditProject/run', method: 'post', data: { ids } })
        const data = res.data || {}
        const lines = (data.summary || []).map((p: anyObj) => {
            const icon = p.issue_total > 0 ? '❌' : '✅'
            return icon + ' ' + p.title + '（' + p.name + '）：' + (p.issue_total > 0 ? p.issue_total + ' 个问题' : '通过') + (p.fail_rules.length ? ' 【' + p.fail_rules.join('、') + '】' : '')
        })
        const text = '本轮审计 ' + data.audited_projects + ' 个项目，共 ' + data.total_issues + ' 个问题' + (data.fail_projects > 0 ? '，' + data.fail_projects + ' 个项目未通过' : '') + '\n\n' + lines.join('\n')
        if (data.fail_projects > 0) {
            ElMessageBox.alert(text, '审计完成（存在未通过项）', { type: 'warning', confirmButtonText: '查看审计结果', dangerouslyUseHTMLString: false })
        } else {
            ElMessage.success('审计完成：' + data.audited_projects + ' 个项目全部通过（共 ' + data.total_issues + ' 个问题）')
        }
        baTable.onTableHeaderAction('refresh', {})
        loadStats()
    } catch {
        // 后端错误已 toast（未找到源码根/无启用项目等）
    } finally {
        running.value = false
    }
}

// ==================== 顶部统计 ====================
const stats = ref<anyObj | null>(null)

const loadStats = async () => {
    try {
        const res = await createAxios({ url: '/admin/audit.AuditProject/stats', method: 'get' })
        stats.value = res.data || null
    } catch {
        stats.value = null
    }
}

onMounted(() => {
    baTable.table.ref = tableRef.value
    baTable.mount()
    baTable.getData()
    loadStats()
})
</script>

<style scoped>
.audit-stats {
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
.table-header-audit-run {
    margin-left: 10px;
}
.table-row-run {
    color: var(--el-color-success) !important;
}
.table-row-switch {
    color: var(--el-color-warning) !important;
}
.table-row-delete {
    color: var(--el-color-danger) !important;
}
</style>