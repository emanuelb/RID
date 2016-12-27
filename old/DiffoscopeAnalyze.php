<?php
// Author: Emanuel Bronshtein @e3amn2l //
// php-cli & php-yaml & php-mbstring packages & PHP >= 7 needed for running this script.

// Directory where diffoscope .txt.gz files located //
$dir = '/data/repbdiffs';
// Path to local updated packages.yml (from notes.git repo)
$LocalPackagesYaml  = 'packages.yml';
// Path to remote updated packages.yml (will be used if $LocalPackagesYaml is not exists/readable)
$RemotePackagesYaml = 'https://anonscm.debian.org/git/reproducible/notes.git/plain/packages.yml';
$RemotePackageNotes = 'https://tests.reproducible-builds.org/debian/rb-pkg/unstable/amd64/%s.html';
$RemotePackageHTMLDiff = 'https://tests.reproducible-builds.org/debian/rb-pkg/%s/%s/diffoscope-results/%s.html';
$RemoteCodeSearch = 'https://codesearch.debian.net/search?q=package%3Ae%s+%s';

$show_packages_notes_urls = true;
// Set high memory_limit in order to support large files #
ini_set('memory_limit', '3800M');
// Set maximum file size that will be scanned
// Recommended <= 14680064 (14MB) , bigger files took lot of time to test them.
$file_max_size_scan = 2500000; // 0 to disable
$file_min_size_scan = 150; // TODO: set to min value via empty file format! [min file downloaded = 1024]

if (!extension_loaded('yaml')) {
    exit('Error: yaml extension not loaded, please install via apt-get install php-yaml');
}
if (!extension_loaded('mbstring')) {
    exit('Error: mbstring extension not loaded, please install via apt-get install php-mbstring');
}

// Display_Errors
ini_set('display_errors', 'On');
error_reporting(E_ALL);

// Based on: https://stackoverflow.com/questions/535020/tracking-the-script-execution-time-in-php#535040
function rutime($ru, $rus, $index)
{
    return ($ru['ru_'.$index.'.tv_sec']*1000 + intval($ru['ru_'.$index.'.tv_usec']/1000))
                -  ($rus['ru_'.$index.'.tv_sec']*1000 + intval($rus['ru_'.$index.'.tv_usec']/1000));
}

// Based on: https://stackoverflow.com/questions/3172332/convert-seconds-to-hourminutesecond#3172665
function format_sec($seconds)
{
    if ($seconds >=1) {
        return $seconds . ' = ' .sprintf('%02d:%02d:%02d', floor($seconds/3600), ($seconds/60)%60, $seconds%60);
    } else {
        return $seconds;
    }
}

// Record Start Time #
$script_start_time = microtime(true);
$script_ru_start   = getrusage();

// lower backtrace maximum limit #

// default 1000000
ini_set('pcre.backtrack_limit', 10000);
// default 100000
ini_set('pcre.recursion_limit', 1000);
// Avoid RCE from packages YAML file #
ini_set('yaml.decode_php', 0);
// show message, to re-test while improve performance!
$max_time_warn=60;
//$strip_more_then_instance_issues  = true;
$skip_packages_with_comments_bugs = false;
// Show details about TP results
$show_tps      = false;
$git_push_show = true;
// echo git push after git commit

$ResultAllNotes    = array();
$FN_Results        = array();
$finds_without_tag = array();
$FN_TrueSetResults = array();
$TP_TrueSetResults = array();
$TP_NS_TrueSetResults = array();
$TSBlackList = array('blacklisted_on_jenkins' , 'blacklisted_on_jenkins_armhf_only',
'ftbfs_old_compat_debheper_compat_level',
'ftbfs_with_-fdebug-prefix-map_error',
'ftbfs_due_to_libtool',
'ftbfs_in_jenkins_setup',
'ftbfs_environment',
'ftbfs_wdatetime',
'ftbfs_uninvestigated_unsatisfiable_dependencies',
'ftbfs_build-indep_not_build_on_some_archs',
'ftbfs_build_depends_not_available_on_amd64',
'ftbfs_due_to_disorderfs',
'ftbfs_uninvestigated_test_failures',
// TODO: somehow detect it?
'timestamps_manually_added_needs_further_investigation',

'max_output_size_reached' // Not relevant, as we use .txt results.
);
// detect relevant issues by word lookup in comment
$comments_lookup_name = array(
    'user_hostname_manually_added_requiring_further_investigation' => array('hostname', 'username'),
    'captures_kernel_version' => array('kernel'),
    'captures_shell_variable_in_autofoo_script' => array('SHELL'),
    '??uname-a??' => array('hostname', 'kernel' , 'build date', 'build time'),
    'timestamps_manually_added_needs_further_investigation' => array('date' , 'time'),
    'build_id_variation_requiring_further_investigation' => array('build id','build_id'),
	'build_id_differences_only' => array('build id','build_id'),
);
// Don't report issues that has related comments
$blacklist_comments = array(
    'user_hostname_manually_added_requiring_further_investigation' => array(
        'bzflag', 'torque',
        'cinder', 'ncbi-blast+',
        'neovim', 'boolector', 'seabios',
		'tcptrace' , // BUG attached fix it ...
		
    ),
    'captures_kernel_version' => array('dynare','mapsembler2')
);
// Known False Positives, don't report them
$KnownFPs = array (
    'captures_home_dir' => array('mrmpi','automake1.11'),
    'captures_shell_variable_in_autofoo_script' => array('nted'),
    'different_due_to_umask' => array('openafs','nfs-utils','stealth',
		'ruby-netcdf', // TODO: check why on local file (on remote no umask variation)
		'scalable-cyrfonts' // because numeric catch
	),
	
	'captures_build_arch' => array('binutils-mingw-w64', 'uclibc', 'libgpg-error' , 'libassuan'),
	
    'random_order_in_java_jar_manifest_mf' => array('spatial4j'),
	'berkeley_db_variation_requiring_further_investigation' => array('petsc') ,
	'different_encoding_in_html_by_docbook_xsl' => array('pygoocanvas'),
	'??tmpglobal??' => array('cairo-dock-plug-ins','fastx-toolkit') 
	//=> array('r-cran-qt')
);

// Don't show the following issues if found
$IssuesSkip = array (
    // not only buildid difference in packages
    'build_id_variation_requiring_further_investigation' => array('arj' , 'pd-iemlib' , 'pdp' , 'icmake', 'ifmail', 'lilo', 'pantomime1.2', 'rockdodger', 'vtun','kmidimon'),
	'build_id_differences_only' => array('arj' , 'pd-iemlib' , 'pdp' , 'icmake', 'ifmail', 'lilo', 'pantomime1.2', 'rockdodger', 'vtun','kmidimon'),
	'timestamps_in_sym_l_files_generated_by_malaga' => array('akonadi' , 'malaga'),
	'randomness_in_binaries_generated_by_sbcl' => array('sbcl'),
	'docbook_to_man_one_byte_delta' => array('libgda5'),
	
);
// Global TODO Section #

# add issue detection: bash_vs_dash (aka if + have -e ?)

// 1. Run my code though static-analysis [aka code-style/other defects..]
    // Currently only PHPCS was used, many issues resolved.
// 2. maximum execution time for FILE , if pass , abort with partial results! + wrote them = thus can debug problems :)
// 3. reduce KnownFPs array (by fixing FPs)
// Add consolidation in Regex global array processing (don't try general rule regex if subissue is found)
// Remove mbstring dependency (change mb_substr call)
// ADD CACHE for SOURCES Query.

class DiffoscopeAnalyze
{
    // general tag >> tags covered by it
    public $subissues = array(
			// Avoid noise reporting [similar issues]
			'unsorted_lua_versions_in_control' => array('random_order_in_lua_version_substvar'), 
			'timestamps_in_documentation_generated_by_org_mode' => array('timestamps_in_org_mode_html_output'), 
			// avoid noise by incomplete rules
			'random_order_in_documentation_generated_by_naturaldocs' => array('timestamps_in_documentation_generated_by_naturaldocs'),
			'captures_users_gecos' => array('texi2html_captures_users_gecos'),
			// subissues
			'random_id_in_pdf_generated_by_dblatex' => array('pdf_id_varying_due_to_build_path',
											'timestamps_in_pdf_generated_by_latex',
											'timestamps_in_documentation_generated_by_htmldoc',
											'random_order_of_pdf_ids_generated_by_latex',
											'pdf_created_by_ghostscript',
											'timestamps_in_pdf_generated_by_apache_fop'),
		'unknownaclass' => array('epydoc'),
		'leaks_path_environment_variable' => array('libtool_captures_shell_build-flags_build-path_path-env'),
		'captures_shell_variable_in_autofoo_script' => array('libtool_captures_shell_build-flags_build-path_path-env'),
		'records_build_flags' => array('libtool_captures_shell_build-flags_build-path_path-env'),
		'unknownaname'=>array('??tex2html??'),
                            'timestamps_in_gjdoc_properties_files' => array('timestamps_in_maven_version_files'),
                            'captures_kernel_version' => array('implementation_version_in_java_manifest_mf'),
                            'captures_build_path' => array(
                              'golang_compiler_captures_build_path_in_binary',
                              'perl_extutils_xspp_captures_build_path',
                              'timestamps_in_beam_files',
                              'records_build_flags',
                              '??build_path_in_PTEX??',
                              '??build_path_by_qdbusxml2cpp??',
                              'hevea_captures_build_path',
							  'captures_build_path_via_assert',
							  'cython_captures_build_path',
							  'libtool_captures_shell_build-flags_build-path_path-env',
                            ),
                            'user_hostname_manually_added_requiring_further_investigation' => array(
                              'user_in_documentation_generated_by_gsdoc',
                              'user_in_java_jar_manifest',
                              'users_and_groups_in_tarball',
                              'users_and_groups_in_cpio_archive',
                              'implementation_version_in_java_manifest_mf'
                            ),
                            'different_encoding' => array(
                                'different_encoding_in_html_by_docbook_xsl',
                                'nroff_output_varies_by_locale_or_utf8',
                                'lynx_dump_varies_output_with_locale'
                            ),
							'??captures_build_path_binary??' => array('captures_build_path_via_assert','captures_build_path'),
                            'timestamps_in_tarball' => array('timestamps_difference_by_unzip'),
                            '??unknownmetagenarator??' => array(
                                'timestamps_in_documentation_generated_by_org_mode' ,
                                'different_encoding_in_html_by_docbook_xsl',
                                'timestamps_in_gjdoc_properties_files',
                                'hevea_captures_build_path',
                                '??path_doxygen??',
                                'm1_timestamp_libre_office'
                            ),
                            '??unknownby??' => array(
							 '??gen_debian_rules??',
							 '??gen_makepkg??',
							'??gtkdoc??' ,
							'??kbergin??' ,
							'??configure??' , 
							'??mytool_rand_data??',
							'timestamps_in_output_generated_by_txt2tags',
							'F1_timestamps_in_manpages_generated_by_txt2man_or_txt2man_dash_p',
							'randomness_in_html_generated_by_texi2html',
							'texi2html_captures_users_gecos',
							'??gnu_M4_path??',
							'??vimsyntaxrb??',
							'libtool_captures_shell_build-flags_build-path_path-env',
								'fontforge_resets_modification_time',
								'timestamps_in_manpages_added_by_golang_cobra',
								'timestamps_in_documentation_generated_by_phpdox',
								'timestamps_in_documentation_generated_by_edoc',
                                'timestamps_in_qmake_makefiles',
                                '??randomnessprotocgengo??',
                                '??timestampverilatedvcd??',
                                '??genconfigtimestamp??',
                                'timestamps_in_documentation_generated_by_naturaldocs',
                                'timestamps_in_documentation_generated_by_man2html',
                                'timestamps_in_jsdoc_toolkit_documentation',
                                'timestamps_in_manpages_generated_by_docbook_utils',
                                '??timestamps_in_manpages_generated_by_gflags2man??',
                                'timestamps_in_documentation_generated_by_pandoc',
                                'timestamp_in_enc_files_added_by_texlive_fontinst',
                                '??timestamps_in_manpages_generated_by_yuck??',
                                'timestamps_in_documentation_generated_by_javadoc',
                                'timestamps_in_manpages_generated_by_yat2m',
                                'timestamps_in_documentation_generated_by_doxygen',
                                '??build_path_by_qdbusxml2cpp??',
                                '??itcl_mkindex_bad_ordering??',
                                '??path_bifcl??',
								'timestamps_in_manpages_generated_by_help2man'
                            )
                        );
    private $preg_error_values = array(
    0 => 'PREG_NO_ERROR',
    1 => 'PREG_INTERNAL_ERROR',
    2 => 'PREG_BACKTRACK_LIMIT_ERROR',
    3 => 'PREG_RECURSION_LIMIT_ERROR',
    4 => 'PREG_BAD_UTF8_ERROR',
    5 => 'PREG_BAD_UTF8_OFFSET_ERROR',
    6 => 'PREG_JIT_STACKLIMIT_ERROR'
    );
	
	public $BypassTestingEnv = true;
	public $envs  = array('unstable' , 'experimental', 'testing');
	public $archs = array('amd64','i386','armhf');

	public $package_target_arch = 'amd64';
	public $PackagesIndexLoadedAll = true;
	public $UseBackUpPackages = false;
	public $UseBackUpBreakages = false;
	public $files = array();
	public $current_rules_count = 0;
	public $global_search_rules_count = 0;
	
	public $rulesNotSkip = array('buildid');
// Store number of +/- lines
    public $add_array_count;
    public $rem_array_count;
    private $ext_for_manual_path =     array('so','a','rds','debug','beam',''.'cma','rds-content','mdb');
	private $current_file_name_unavailable = '?N/A?';
    public $NotesResult = array();
// false to disable
    public $RawResults  = true;
// false to disable
	public $RemoveFilesWithFixedBugs = false;
	public $diffoscope_forever_arr = array();
	public $packages_index_arr = array();
	
    private $line='';
    private $linenumber=0;
	private $invalidcounter=1;
    private $package_name='';
    private $current_file_name='';
    private $issues_both_values;
    public $linecount = 0;
    public $timestart;
    public $cputimestart;
    private $start_order_check = array(
        'file list',
        'line order',
        'sqlite3 {} .dump',
        'zipinfo {}',
        'isoinfo -l -i {} -R',
        'isoinfo -l -i {}',
		'objdump --line-numbers --disassemble --demangle --section=.text {}'
    );
    private $ext_for_order     = array('html','pom','Named','hhp','xml','qhp');
	private $filenames_for_order = array('./clilibs');
    public $skip_type_when_found = false; // TODO: Rerun with false [bad performance / but good results]
    private $skip_type_when_found_arr = array();
    private $limitstartchange = '{1,15}';
// use + for infinity
    private $limitstartfile = '{0,15}';
    private $rules = array();
    public $result = array();
    public $Debug = false;
    private $skip_file_names = array(
    'Checksums-Sha256',
    'md5sums',
    'file list',
    'line order',
    'encoding',
    'metadata',
        
    'pdftotext {} -',
    'pdftk {} output - uncompress',
    'ps2ascii {}',
    'isoinfo -l -i {}',
    'isoinfo -l -i {} -R',
        
    'sng',
    'cd-iccdump {}',
    'showttf {}',
    'zipinfo {}',
    'zipinfo -v {}',
    'pedump {}',
    'msgunfmt {}',
    'sqlite3 {} .dump',
    'javap -verbose -constants -s -l -private {}',

    'objdump --line-numbers --disassemble --demangle --section=.text {}',
    'objdump --line-numbers --disassemble --demangle --section=.text.unlikely {}',
    'objdump --line-numbers --disassemble --demangle --section=.text.exit {}',
    'objdump --line-numbers --disassemble --demangle --section=.text.startup {}',
    'objdump --line-numbers --disassemble --demangle --section=.init {}',
    'objdump --line-numbers --disassemble --demangle --section=.plt.got {}',
        
    'readelf --wide --debug-dump=abbrev {}',
    'readelf --wide --debug-dump=aranges {}',
    'readelf --wide --debug-dump=frames {}',
    'readelf --wide --debug-dump=gdb_index {}',
    'readelf --wide --debug-dump=info {}',
    'readelf --wide --debug-dump=loc {}',
    'readelf --wide --debug-dump=macro {}',
    'readelf --wide --debug-dump=pubnames {}',
    'readelf --wide --debug-dump=pubtypes {}',
    'readelf --wide --debug-dump=ranges {}',
    'readelf --wide --debug-dump=rawline {}',
    'readelf --wide --debug-dump=trace_abbrev {}',
    'readelf --wide --debug-dump=trace_aranges {}',
    'readelf --wide --debug-dump=trace_info {}',
    'readelf --wide --decompress --hex-dump=.bss {}',
    'readelf --wide --decompress --hex-dump=.data {}',
    'readelf --wide --decompress --hex-dump=.data.rel.ro {}',
    'readelf --wide --decompress --hex-dump=.dynstr {}',
    'readelf --wide --decompress --hex-dump=.eh_frame {}',
    'readelf --wide --decompress --hex-dump=.eh_frame_hdr {}',
    'readelf --wide --decompress --hex-dump=.fini_array {}',
    'readelf --wide --decompress --hex-dump=.gcc_except_table {}',
    'readelf --wide --decompress --hex-dump=.gnu_debuglink {}',
    'readelf --wide --decompress --hex-dump=.got {}',
    'readelf --wide --decompress --hex-dump=.got.plt {}',
    'readelf --wide --decompress --hex-dump=.init_array {}',
    'readelf --wide --decompress --hex-dump=.note.GNU-stack {}',
    'readelf --wide --decompress --hex-dump=.rodata {}',
    'readelf --wide --decompress --hex-dump=.shstrtab {}',
    'readelf --wide --decompress --hex-dump=.strtab {}',
    'readelf --wide --decompress --string-dump=.debug_str {}',
    'readelf --wide --dynamic {}',
    'readelf --wide --file-header {}',
    'readelf --wide --notes {}',
    'readelf --wide --program-header {}',
    'readelf --wide --relocs {}',
    'readelf --wide --sections {}',
    'readelf --wide --symbols {}',
    'readelf --wide --version-info {}'
    );
// RULES #

    // Variations RAW data //

    // HostName #
    private $hostnames1=array(
    'profitbricks-build1-amd64',
    'profitbricks-build5-amd64',
    'profitbricks-build2-i386',
    'profitbricks-build6-i386',
    'bbx15-armhf-rb',
    'bpi0-armhf-rb',
    'cb3a-armhf-rb',
    'cbxi4a-armhf-rb',
    'cbxi4b-armhf-rb',
    'cbxi4pro0-armhf-rb',
    'ff2a-armhf-rb',
    'ff2b-armhf-rb',
    'ff4a-armhf-rb',
    'hb0-armhf-rb',
    'odu3a-armhf-rb',
    'odxu4-armhf-rb',
    'odxu4b-armhf-rb',
    'odxu4c-armhf-rb',
    'opi2a-armhf-rb',
    'opi2b-armhf-rb',
    'opi2c-armhf-rb',
    'rpi2b-armhf-rb',
    '$rpi2c-armhf-rb',
    'wbd0-armhf-rb',
    'wbq0-armhf-rb'
    );
    private $hostname2='i-capture-the-h';
//i-capture-the-hostname #strip in freewnn package

    // DomainName #

    private $domainname1='debian.net';
    private $domainname2='i-capture-the-d';
// i-capture-the-domainname

    // ENV VAR #
    // CAPTURE_ENVIRONMENT="I capture the environment"
    private $env_var=array('CAPTURE ENVIRONMENT','I capture the environment');
    private $kernel1='3.16';
    private $kernel2='4.6';
// separators //

    private $possible_separators = array (
    '-',
    '_',
    ' ',
	'\/'
    );
// GMT //

    private $GMT1='+12';
    private $GMT2='-14';
// SHELL //
    private $shell1='/bin/sh';
    private $shell2='/bin/bash';
// PATH ENV //

    private $pathenv='/i/capture/the/path';
    private $username1='pbuilder1';
    private $username2='pbuilder2';
// UID //

    private $uid1='1111';
    private $uid2='2222';
// DATE Rules //
    
    // Year/Month/Day
    private $date1 = '2016/08/29';
    private $date2 = '2017/10/02';
    private $year1 = 2016;
    private $year2 = 2017;
    private $month1 = 8;
    private $month2 = 10;
    private $day1 = 29;
    private $day2 = 2;
    private $homedir1='first-build';
    private $homedir2='second-build';
    private $path1='build-1st';
    private $path2='build-2nd';
// GECOS //
    private $GECOS1=array(
    //'first user',
    'first room',
    'first work-phone',
    'first home-phone',
    'first other'
    );
    private $GECOS2=array(
    'second user', // comment this
    'second room',
    'second work-phone',
    'second home-phone',
    'second other'
    );
	
	private $code_search_rules = array (
	
		 // when found different_encoding > can check this...
		 'lynx_dump_varies_output_with_locale' => 'lynx+-dump',
		 'elinks_dump_varies_output_with_locale ' => 'elinks+-dump',
		 
		 
		 

'timestamps_in_pdf_generated_by_apache_fop' => 'Apache FOP Version ',

// need detect file with .pdf for codesearch ghostscripts...
'pdf_created_by_ghostscript' => 'ghostscript' ,
'timestamps_in_pdf_generated_by_latex' => 'pdflatex',
'xmlto_txt_output_locale_specific' => 'xmlto',
 'timestamps_in_qmake_makefiles' => 'qmake',
 'timestamps_in_org_mode_html_output' => 'org-html'


		 
	);
	
    private $GlobalSearch = array();
	private $GlobalSearchRules = array(
	
		'haskell' => array(
		  'search' => 'haskell',
		),	
		
		'python' => array(
		  'search' => 'python',
		),
		// TODO: add as issue without order check? = to ensure order check work?
		'getypebase' => array(
		  'search' => '_get_type@@Base>'
		),
	
	);

	
	
    private $rules_content_search = array(

    'createdusingsphinx' => array(
      'search' => 'Created using <a href="http://sphinx-doc.org/">Sphinx</a> ',
      'tag' => 'copyright_year_in_documentation_generated_by_sphinx'
    ),
	
    'last_updated_on' => array(
      'search' => 'Last updated on ',
      'tag' => '??last_updated_on??'
    ),	
	
    'makecheckgen' => array(
      'search' => 'Generated and used by "make check"',
      'tag' => '??make_check_generated??'
    ),
	
	
    'xbeanspring' => array(
      'search' => '#Generated by xbean-spring',
      'tag' => 'timestamps_generated_by_xbean_spring'
    ),

	/* 
	TODO: if HTML scanning implemented:
	
	'maxdifflines' => array(
      'search' => '>Max diff block lines reached',
      'tag' => 'max_output_size_reached'
    ),

	'maxoutputsize' => array(
      'search' => '>Max output size reached.',
      'tag' => 'max_output_size_reached'
    ),
	
	*/
	
	 'alabasterorder' => array(
      'search' => '<li class="toctree-l1"><a href="',
      'tag' => 'dict_ordering_in_python_alabaster_sphinx_theme_extra_nav_links'
    ),	
	
	
	 'pythonshebang' => array(
      'search' => '+#!/usr/bin/python3',
      'tag' => 'python_shebang_and_dependency_nondeterministically_3_or_3_point_5'
    ),	
	 
	 '??docutils??' => array(
      'search' => 'class="docutils"',
      'tag' => '??docutils??' // maybe:  timestamps_in_python_docutils 
    ),	

	 	
	'missingdiff1' => array(
    'search' => '  Installed-Build-Depends:', 
    'tag' => '??missing_diff1??'
    ),

	
    'ghcsome' => array(
      'search' => '-Ghc-Package:',
      'tag' => '??ghcsome??'
    ),	
	
    'luasortversioncontrol' => array(
      'search' => ' -Lua-Versions: ',
      'tag' => 'unsorted_lua_versions_in_control'
    ),
	
    'luasortversionsubstvar' => array(
      'search' => 'lua:Versions=',
      'tag' => 'random_order_in_lua_version_substvar'
    ),
	
	// Need TEST packages [didn't know about package with this issues] //
    'tiestampmalaga' => array(
      'search' => 'Malaga',
      'tag' => 'timestamps_in_sym_l_files_generated_by_malaga'
    ),
	  
    'tiestampmpyside' => array(
      'search' => 'The Resource Compiler for PySide',
      'tag' => 'timestamps_in_python_code_generated_by_pyside'
    ),	
	
	// End need TEST packages //

	'gtkdoc' => array(
      'search' => 'Generated by GTK-Doc',
      'tag' => '??gtkdoc??'
    ),	
	
    'makefilepljavapath' => array(
      'search' => 'created by the Makefile.PL',
      'tag' => '??makefilepljavapath??'
    ),	
	
    'kbergin' => array(
      'search' => 'Created by kbergin ',
      'tag' => '??kbergin??'
    ),	
				
    'buildids' => array(
      'regex' => '/(Build\-Ids: [0-9a-f]{40})/',
      'tag' => '??buildids??'
    ),	
	
    'configure' => array(
      'regex' => '/(Created by \.?\/?configure )/',
      'tag' => '??configure??'
    ),		
	
    'vimsyntaxrb' => array(
      'search' => 'generated by VimSyntax.rb',
      'tag' => '??vimsyntaxrb??'
    ),	
	
	
    'libtoolgnu' => array(
      'search' => 'Generated by libtool ', // Generated by libtool (GNU libtool)
      'tag' => 'libtool_captures_shell_build-flags_build-path_path-env'
    ),

	'debianrules' => array(
      'search' => 'generated by debian/rules',
      'tag' => '??gen_debian_rules??'
    ),	
	
	/*
	ARCH: https://tests.reproducible-builds.org/archlinux/community/v4l2ucp/v4l2ucp-2.0.2-2-x86_64.pkg.tar.xz.html
	'genmakepkg' => array(
      'search' => 'Generated by makepkg',
      'tag' => '??gen_makepkg??'
    ),		
	*/
	
	
	'txt2tags' => array(
      'search' => 'generated by txt2tags ',
      'tag' => 'timestamps_in_output_generated_by_txt2tags'
    ),
	
	'txt2man' => array(
      'search' => ' txt2man',
      'tag' => 'F1_timestamps_in_manpages_generated_by_txt2man_or_txt2man_dash_p'
    ),
	
    'gnum4' => array(
      'search' => 'generated by GNU M4 ',
      'tag' => '??gnu_M4_path??'
    ),
		
    'qtdoc' => array(
      'search' => ' <qtPageIndex>',
      'tag' => 'randomness_in_qdoc_page_id'
    ),
	
    'graphvizfffff' => array(
      'search' => ' +ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff ffffff',
      'tag' => 'graphviz_nondeterminstic_output'
    ),
	
    'gjdoc1' => array(
      'search' => '<meta name="generator" content="GNU Gjdoc Standard Doclet',
      'tag' => 'timestamps_in_gjdoc_properties_files'
    ),	

    'gjdoc1' => array(
      'search' => '<meta name="generator" content="GNU Gjdoc Standard Doclet',
      'tag' => 'timestamps_in_gjdoc_properties_files'
    ),
	
    'gjdoc2' => array(
      'search' => 'Generated by Gjdoc HtmlDoclet',
      'tag' => 'timestamps_in_gjdoc_properties_files'
    ),
	
    'generatedbyedoc' => array(
      'search' => 'Generated by EDoc, ',
      'tag' => 'timestamps_in_documentation_generated_by_edoc'
    ),
	
	/*
    //TODO: combine timestampdoxygen1 & timestampdoxygen2 with regex?
    'timestampdoxygen1' => array(
      'search' => 'by <a href="http://www.doxygen.org/index.html">',
      'tag' => 'timestamps_in_documentation_generated_by_doxygen'
    ),
    
    'timestampdoxygen2' => array(
      'search' => 'by <a target="_blank" href="http://www.doxygen.org/index.html">',
      'tag' => 'timestamps_in_documentation_generated_by_doxygen'
    ),
	*/
	
    'timestampdoxygen' => array(
      'regex' => '@(by <a (target="_blank" )?href="http://www\.doxygen\.org/index\.html">)@',
      'tag' => 'timestamps_in_documentation_generated_by_doxygen'
    ),
    
    'pathdoxygen1' => array(
      'search' => '<meta name="generator" content="Doxygen',
      'tag' => '??path_doxygen??'
    ),
	
    'pathdoxygen2' => array(
      'search' => ' doxygen="',
      'tag' => '??path_doxygen??'
    ),	
	 
    
    // package: tkdesk
    'itclmkindexbadordering' => array(
      'search' => 'This file is generated by the "itcl_mkindex" command',
      'tag' => '??itcl_mkindex_bad_ordering??'
    ),
    
    'timestamplibreoffice' => array(
      'search' => '<meta name="generator" content="LibreOffice ',
      'tag' => 'm1_timestamp_libre_office'
    ),

	//TODO: check, probably FPs
    '??cppmacrosd??' => array(
      'search' => ' 2017.- .|',
      'tag' => 'timestamps_from_cpp_macros_in_d'
    ),
	
    'pathbifcl' => array(
      'search' => 'This file was automatically generated by bifcl from',
      'tag' => '??path_bifcl??'
    ),
    
    'javadocmethodnever' => array(
      'search' => 'This method may never be called',
      'tag' => '??method_may_never_be_called_in_documentation_generated_by_javadoc??'
    ),
    
    'dhcligacpolicy' => array(
      'search' => 'Automatically added by dh_cligacpolicy',
      'tag' => 'randomness_in_dh_cligacpolicy_scripts'
    ),

    'pathqdbusxml2cpp' => array(
      'search' => 'was generated by qdbusxml2cpp ', // This file was generated by qdbusxml2cpp version 0.8
      'tag' => '??build_path_by_qdbusxml2cpp??'
     ),
    
    'pathhevea' => array(
      'search' => '<meta name="generator" content="hevea ',
      'tag' => 'hevea_captures_build_path'
    ),
    
    
   // <title>500 Internal Server Error</title> in content downloaded
    'wgeterror' => array(
      'search' => 'holger@layer-acht.org to inform',
      'tag' => '??wget_error_500??'
    ),
    
    'cobra' => array(
      'search' => 'Auto generated by spf13/cobra',
      'tag' => 'timestamps_in_manpages_added_by_golang_cobra'
    ),

    'javacodechange' => array(
      'search' => 'javap -verbose -constants -s -l -private {}',
      'tag' => '??change_in_java_code??'
    ),

    // in "montage" (0x312d646c6975622f|0x322d646c6975622f)
	// maybe 'captures_build_path_via_assert' issue
    'buildpathhex' => array(
      'search' => '0x312d646c697562',
      'tag' => '??captures_build_path_binary??'
    ),
        
    'dllstrongname' => array(
      'search' => '¦  Strong name:',
      'tag' => '??cryptographic_signature_dll_strong_name??'
    ),

    'manifestorder' => array(
      'search' => 'Private-Package: ',
      'tag' => 'random_order_in_java_jar_manifest_mf'
    ),
	
    // TODO: FPs, aka report on random_order_in_documentation_generated_by_naturaldocs
    'naturaldocs' => array(
      'search' => 'Generated by Natural Docs</a>',
      'tag' => 'timestamps_in_documentation_generated_by_naturaldocs'
    ),

    'dvipsource' => array(
      'search' => '+%DVIPSSource',
      'tag' => 'timestamps_in_ps_generated_by_dvips'
    ),

    'dvipscreate' => array(
      'search' => '+%%CreationDate',
      'tag' => 'timestamps_in_ps_generated_by_dvips'
    ),

    'varnish' => array(
      'search' => '.Varnish ',
      'tag' => 'varnish_vmodtool_random_file_id'
    ),
    
    'uid' => array(
      'search' => '(1111)',
      'tag' => 'users_and_groups_in_tarball'
    ),
    	
    'timestampsjavadoc' => array(
      'search' => '<!-- Generated by javadoc ',
      'tag' => 'timestamps_in_documentation_generated_by_javadoc'
    ),

    'javadoclocale' => array(
      'search' => '+<html lang="fr">',
      'tag' => 'locale_in_documentation_generated_by_javadoc'
    ),

    'timestampscheetah' => array(
      'search' => '+__CHEETAH_srcLastModified__ =',
      'tag' => 'timestamps_in_python_code_generated_by_cheetah'
    ),
        
    'jsdoctoolkit' => array(
      'search' => '<a href="http://code.google.com/p/jsdoc-toolkit/" target="_blank">JsDoc Toolkit</a>',
      'tag' => 'timestamps_in_jsdoc_toolkit_documentation'
    ),
        
        
    'timestampsqmake' => array(
      'search' => '+# Generated by qmake ',
      'tag' => 'timestamps_in_qmake_makefiles'
    ),
        
    'fontforgets' => array(
      'search' => 'by FontForge',
      'tag' => 'fontforge_resets_modification_time'
    ),
        
    'ckbuilder' => array(
      'search' => 'For licensing, see LICENSE.md or http://ckeditor.com/license',
      'tag' => 'copyright_year_in_comments_generated_by_ckbuilder'
    ),
    
    'jsdoc' => array(
      'search' => 'Documentation generated by <a href="http://jsdoc.sourceforge.net/">',
      'tag' => 'timestamps_in_jsdoc_toolkit_documentation'
    ),

    'yat2m' => array(
      'search' => '" Created from Texinfo source by yat2m ',
      'tag' => 'timestamps_in_manpages_generated_by_yat2m'
    ),
        
    'rescompiler' => array(
      'search' => '# Created by: The Resource Compiler for ',
      'tag' => '??rescompiler??'
    ),

    'htmldoc' => array(
      'search' => 'Producer (htmldoc',
      'tag' => 'timestamps_in_documentation_generated_by_htmldoc'
    ),
        
    'timestampsasciidoctor' => array(
      'search' => '" Generator: Asciidoctor ',
      'tag' => 'timestamps_in_documentation_generated_by_asciidoctor'
    ),
        
    'timestampsmanpagemaybenroff' => array(
      'search' => '" For nroff, turn off justification.  Always turn off hyphenation; it makes',
      'tag' => '??timestamps_in_manpage nroff somehow related??'
    ),

    'docbook2man' => array(
      'search' => '" This manpage has been automatically generated by docbook2man',
      'tag' => 'timestamps_in_manpages_generated_by_docbook_utils'
    ),

    'gflags2man' => array(
      'search' => '" DO NOT MODIFY THIS FILE!  It was generated by gflags2man ',
      'tag' => '??timestamps_in_manpages_generated_by_gflags2man??'
    ),

    'pandoc' => array(
    'search' => '" Automatically generated by Pandoc ',
    'tag' => 'timestamps_in_documentation_generated_by_pandoc'
    ),

    'timestampenc' => array(
    'search' => 'automatically generated by fontinst from',
    'tag' => 'timestamp_in_enc_files_added_by_texlive_fontinst'
    ),

    'timestampfdfontinst' => array(
    'search' => '%Created using fontinst',
    'tag' => '??timestamp_in_fd_files_added_by_??_fontinst??'
    ),

    'timestampyuck' => array(
    'search' => ' auto generated by yuck ',
    'tag' => '??timestamps_in_manpages_generated_by_yuck??'
    ),

    'timestampreportlab' => array(
    'search' => '/Producer (ReportLab PDF Library - ',
    'tag' => 'timestamps_in_pdf_generated_by_reportlab',
	'codesearch' => array('reportlab' , 'rst2pdf')
    ),

    'timestampautogen' => array(
    'search' => '" It has been AutoGen-ed',
    'tag' => 'timestamps_in_manpages_generated_by_autogen'
    ),

    'rst2man' => array(
    'search' => '.nr rst2man-indent-level ',
    'tag' => 'timestamps_in_manpages_generated_by_rst2man'
    ),

    'matplotlibpdf' => array(
    'search' => 'Producer (matplotlib pdf backend)',
    'tag' => 'timestamps_in_pdf_generated_by_matplotlib',
	'codesearch' => array('matplotlib')
    ),

    'man2html' => array(
    'search' => 'This document was created by man2html from ',
    'tag' => 'timestamps_in_documentation_generated_by_man2html'
    ),

    'gnu_debuglink' => array(
    'search' => 'Hex dump of section \'.gnu_debuglink\':',
    'tag' => '??gnu_debuglink??'
    ),
        
    'texi2html' => array(
    'search' => 'using <a href="http://www.nongnu.org/texi2html/"><em>texi2html',
    'tag' => 'randomness_in_html_generated_by_texi2html'
    ),

    'gecos_second_user' => array(
    'search' => 'second user',
    'tag' => '??gecos_second_user??'
    ),
        
    'docorgmode' => array(
    'search' => '<a href="http://orgmode.org">Org</a>',
    'tag' => 'timestamps_in_documentation_generated_by_org_mode'
    ),
        
    'docorgmodemeta' => array(
    'search' => '<meta  name="generator" content="Org-mode"',
    'tag' => 'timestamps_in_documentation_generated_by_org_mode'
    ),

    'timestampemacsorg' => array(
    'regex' => '@(<p class="creator"><a href="http://www\.gnu\.org/software/emacs/">Emacs</a> \d+\.\d+(?:\.\d+)? \(<a href="http://orgmode\.org">Org</a> mode )@',
    'tag' => 'timestamps_in_org_mode_html_output'
    ),
	
	'scilabrandom' => array(
    'regex' => '/( href="section_\w{32,40}\.html")/', 
    'tag' => 'randomness_in_documentation_generated_by_scilab'
    ),	
	
	// NOTE: may return 'PREG_BACKTRACK_LIMIT_ERROR' (was changed to {0,20} as partial fix)
	/*
		Maybe the original mean only:
			https://tests.reproducible-builds.org/debian/rb-pkg/unstable/amd64/diffoscope-results/astroscrappy.html
			AKA:
				static·?const·?char·?__pyx_k_build_1st_ast?roscrappy_1_0_5_as[]·?=·?"/?build-?1st/?astroscrappy-?1.?0.?5/?astroscrappy/?astroscrappy.?pyx";?
	*/
	'cythonpath' => array(
    'regex' => '/(__pyx(?:_\w{1,20}){0,20}_build_1st_)/', // (?:2nd|1st)
    'tag' => 'cython_captures_build_path'
    ),
	
	'hrefmanglednode' => array(
    'regex' => '/(<a href="(&#\w{3};)+&#x40;(&#\w{3};)+">)/',
    'tag' => 'href_links_mangled_by_node_marked'
    ),
	
	'sbclbin' => array(
    'regex' => '/(Depends: .{0,100}sbcl)/',
    'tag' => 'randomness_in_binaries_generated_by_sbcl'
    ),
		

	'unamepython' => array(
    'search' => 'x86_64\' does not exist -- can\'t clean it', 
    'tag' => ' uname_output_in_python_debugging_symbols_caused_by_sysconfig_getplatform'
    ),

	
    'docbookxslhtml' => array(
    'search' => '<meta name="generator" content="DocBook XSL Stylesheets',
    'tag' => 'different_encoding_in_html_by_docbook_xsl'
    ),
        
    'genconfigtimestamp' => array(
    'search' => '# This file was auto-generated by genconfig on ',
    'tag' => '??genconfigtimestamp??'
    ),
        
    'timestampverilatedvcd' => array(
    'search' => ' Generated by VerilatedVcd ',
    'tag' => '??timestampverilatedvcd??'
    ),
         
    'randomnessprotocgengo' => array(
    'search' => '// Code generated by protoc-gen-go.',
    'tag' => '??randomnessprotocgengo??'
    ),
        
    'docbookxslman' => array(
    'search' => '" Generator: DocBook XSL Stylesheets ',
    'tag' => 'timestamps_in_manpages_generated_by_docbook_xsl'
    ),
        
    'qmake' => array(
    'search' => 'Generated by qmake ',
    'tag' => 'timestamps_in_qmake_makefiles'
    ),

    'potcreationdate' => array(
    'search' => '+"POT-Creation-Date: ',
    'tag' => 'different_pot_creation_date_in_gettext_mo_files'
    ),
        
    'latex2man' => array(
    'search' => 'Manual page created with latex2man on ',
    'tag' => 'timestamps_in_manpages_generated_by_latex2man'
    ),
        
    'lualdoc' => array(
    'search' => 'generated by <a href="http://github.com/stevedonovan/LDoc">LDoc',
    'tag' => 'timestamps_in_documentation_generated_by_lua_ldoc'
    ),

    'luasortversion' => array(
    'search' => ' +Lua-Versions: ',
    'tag' => 'unsorted_lua_versions_in_control'
    ),
	
	
    'mangosdk' => array(
    'search' => 'Generated by org.mangosdk.spi.processor.SpiProcessor',
    'tag' => 'timestamps_generated_by_mangosdk_spiprocessor'
    ),

    'epydoc' => array(
    'search' => '<h3 class="epydoc">',
    'tag' => 'randomness_in_documentation_generated_by_epydoc'
    ),

    'apachefop' => array(
    'search' => 'Apache FOP Version ',
    'tag' => 'timestamps_in_pdf_generated_by_apache_fop',
	'codesearch' => 'fop+'
    ),

    'texinfomdate' => array(
    'search' => 'akeinfo version',
    'tag' => 'texinfo_mdate_sh_varies_by_timezone'
    ),
	
    'timestampphpdox' => array(
    'search' => 'Generated using phpDox ',
    'tag' => 'timestamps_in_documentation_generated_by_phpdox'
    ),	
	
    'rdtoolpath' => array(
    'search' => '<!-- RDLabel: ',
    'tag' => 'm1_rdtoolpath'
    ),		
	
	
    'captureparallel' => array(
    'regex' => '/(parallel[=:]?\s{0,50}18)/',
    'tag' => '??captureparallel??'
    ),	
	
    'ghctmppath' => array(
    'regex' => '/(\/tmp\/ghc\w{4,6}_0)/',
    'tag' => 'ghc_captures_build_path_via_tempdir'
    ),

    'tmpglobal' => array(
    'regex' => '/(\/tmp\/\w{4,6})/',
    'tag' => '??tmpglobal??'
    ),	
	
	
    // General Tool usage search //
    'unknownmetagenarator' => array(
    'regex' => '/(<meta\s+name=["\']?generator["\']?\s+content=.{1,15})/',
    'tag' => '??unknownmetagenarator??'
    ),

    'unknownaname' => array(
    'regex' => '/(<a\s+name=["\' ].{1,15})/',
    'tag' => '??unknownaname??'
    ),
	
    'unknownaclass' => array(
    'regex' => '/(class=["\' ].{1,15})/',
    'tag' => '??unknownclass??'
    ),

	
    'unknownby' => array(
    'regex' => '/((?:produced|generated|buil[dt]|created|[^e]source|Generator) (?:by|using|with|and):? (?!various|pbuilder|the|hardware|sigplot|new|debootstrap|\/build\-|liberty|"all"|Ross|this|msgcat).{1,15})/',
    'tag' => '??unknownby??'
    ),
	
	// gnuplot [gecos+date]
    'tex2html' => array(
    'regex' => '/(<A NAME="tex2html\d{1,8}")/',
    'tag' => '??tex2html??'
    ),
	
    'buildflagssearch' => array(
    'regex' => '/(CC?X{0,2}FLAGS|Build flags:)/',
    'tag' => '??build_flags_capture_search??'
    ),
	 // 
    'golanghex' => array(
    'search' => ' --hex-dump=.gopclntab ',//'readelf --wide --decompress --hex-dump=.gopclntab {} ',
    'tag' => '??golanghex??'
    ),
	
    'xsppath' => array(
    'search'     => '/usr/bin/perl -MExtUtils::XSpp::Cmd -e xspp',
    //'startwith' => '/usr/bin/perl -MExtUtils::XSpp::Cmd -e xspp', # only if used in constant search per line
    'tag' => 'perl_extutils_xspp_captures_build_path'
    ),

    'help2man' => array(
    'search'     => 'generated by help2man',
    //'startwith' => 'generated by help2man', # only if used in constant search per line
    'tag' => 'timestamps_in_manpages_generated_by_help2man'
    ),
    );
// Rules Constant Data //
    
    private $rules_constant=array(
           
		'golangpath' => array(
		'regex' => '/(usr\/lib\/go\-\d\.\d)/',
		'tag' => 'golang_compiler_captures_build_path_in_binary' // random_build_path_by_golang_compiler
		),
		
		// yudit
		'mytoolrand' => array(
		'search' => 'created by mytool',
		'tag' => '??mytool_rand_data??'
		),

    );
// FileName Rules //

    // type: 0=regex , 1=startwith, 2=endwith, 3=startwith+endwith, 4=search
    private $rules_filenames = array(
    'gsdoc' => array(
    'regex' => '(?P<gsdoc_r>\.\/usr\/share\/GNUstep\/Documentation\/.*\.(?:html|gsdoc)$)',
    'startwith' => './usr/share/GNUstep/Documentation',
    'endwith' => array('.html','.gsdoc'),
    'tag' => 'user_in_documentation_generated_by_gsdoc',
    'type' => 3
    ),

    'gpgkeyring' => array(
    'regex' => '(?P<gpgkeyring_r>\.\/usr\/share\/.*\.gpg$)',
    'startwith' => './usr/share/',
    'endwith' => '.gpg',
    'tag' => 'gpg_keyring_magic_bytes_differ',
    'type' => 3
    ),
        
		
    'chasen' => array(
    'regex' => '(?P<chasen_r>\.\/var\/lib\/chasen\/.*\.dat$)',
    'startwith' => './var/lib/chasen/',
    'endwith' => '.dat',
    'tag' => 'random_contents_in_dat_files_generated_by_chasen-dictutils_makemat',
    'type' => 3
    ),

    'xbeanspring' => array(
    'regex' => '(?P<xbeanspring_r>META\-INF\/spring\.(?:handlers|schemas)$)',
    'startwith' => 'META-INF/spring.',
    'endwith' => array('handlers','schemas'),
    'tag' => 'timestamps_added_by_xbean_spring',
    'type' => 3
    ),
       
    'pearreg' => array(
    'regex' => '(?P<pearreg_r>\.\/usr\/share\/php\/\.registry\/\.reg$)',
    'startwith' => './usr/share/php/.registry/',
    'endwith' => '.reg',
    'tag' => 'timestamp_in_pear_registry_files',
    'type' => 3
    ),		
	  
		
    // also maven.version.number?
    'timestampsmaven' => array(
    'regex' => '(?P<timestampsmaven_r>^[^.].+\.properties$)',
    'endwith' => '.properties',
    'tag' => 'timestamps_in_maven_version_files',
    'type' => 0
    ),
        
    'timestampsjavautil' => array(
    'regex' => '(?P<timestampsjavautil_r>^.+\..+\.properties$)',
    'endwith' => '.properties',
    'tag' => 'timestamp_added_by_java_util_properties',
    'type' => 0
    ),
    
     'rdb' => array(
    'regex' => '(?P<rdb_r>\.\/usr\/lib\/R\/(site\-)?library\/.*\.(?:rdb|rds|rdx)$)',
    'startwith' => array('./usr/lib/R/site-library/','./usr/lib/R/library/'),
    'endwith' => array('.rdb','.rds','.rdx'),
    'tag' => 'randomness_in_r_rdb_rds_databases',
    'type' => 3
    ),

    'iccprofiles' => array(
    'regex' => '(?P<iccprofiles_r>\.icc$)',
    'endwith' => '.icc',
    'tag' => 'randomness_in_icc_colour_profiles',
    'type' => 2
    ),

    'ppufpc' => array(
    'regex' => '(?P<ppufpc_r>\.ppu$)',
    'endwith' => '.ppu',
    'tag' => 'timestamps_in_ppu_generated_by_fpc',
    'type' => 2
    ),

    'whl' => array(
    'regex' => '(?P<whl_r>\.whl$)',
    'endwith' => '.whl',
    'tag' => 'python_wheel_package',
    'type' => 2
    ),		
			 
    'plyparsetab' => array(
    'regex' => '(?P<plyparsetab_r>parsetab\.py$)',
    'endwith' => 'parsetab.py',
    'tag' => 'python-ply_compiled_parse_tables',
    'type' => 2
    ),				  
    
    'jsfile' => array(
    'regex' => '(?P<jsfile_r>\.js$)',
    'endwith' => '.js',
    'tag' => 'F1_js-randomness_in_browserify_lite_output',
    'type' => 2
    ),
	
	
    // as in "nss" package
    // maybe add startwith ./usr/lib/
    'cryptochk' => array(
    'regex' => '(?P<cryptochk_r>\.chk$)',
    'endwith' => '.chk',
    'tag' => 'cryptographic_signature',
    'type' => 2
    ),

    'mavenlocalxml' => array(
    'regex' => '(?P<mavenlocalxml_r>\/maven\-metadata\-local\.xml$)',
    'endwith' => '/maven-metadata-local.xml',
    'tag' => 'timestamps_in_maven_metadata_local_xml_files',
    'type' => 2
    ),
	
	'timestampogg' => array(
    'regex' => '(?P<timestampogg_r>\.ogg$)',
    'endwith' => '.ogg',
    'tag' => 'serial_numbers_in_ogg',
    'type' => 2
    ),
	
	'timestampepub' => array(
    'regex' => '(?P<timestampogg_r>\.epub$)',
    'endwith' => '.epub',
    'tag' => 'timestamps_in_epub',
    'type' => 2
    ),	
	  
	'casacoretables' => array(
    'regex' => '(?P<casacoretables_r>table\.f0$)',
    'endwith' => 'table.f0',
    'tag' => 'nondeterminstic_ordering_in_casacore_tables',
    'type' => 2
    ),
	  
	
    'timestampsrbase' => array(
    'regex' => '(?P<timestampsrbase_r>\.\/usr\/lib\/R\/site\-library\/.*\.DESCRIPTION$)',
    'startwith' => './usr/lib/R/site-library/',
    'endwith' => 'DESCRIPTION',
    'tag' => '??timestamps_in_description_files_generated_by_r-base-dev'.
        '|OR|r_base_appends_built_header_to_description_files??',
    'type' => 3
    ),
    
    
    'plist' => array(
    'regex' => '(?P<plist_r>\.\/usr\/share\/GNUstep.*\.plist$)',
    'startwith' => './usr/share/GNUstep',
    'endwith' => '.plist',
    'tag' => 'plist_weirdness',
    'type' => 3
    ),

    'dbfile' => array(
    'regex' => '(?P<dbfile_r>\.\/usr\/lib\/.*\.db$)',
    'startwith' => './usr/lib/',
    'endwith' => '.db',
    'tag' => 'berkeley_db_variation_requiring_further_investigation',
    'type' => 3
    ),

    'glibenums' => array(
    'regex' => '(?P<glibenums_r>\.\/usr\/share\/glib-\d\.\d\/.*\.xml$)',
    //'startwith' => './usr/share/glib-',
    //'endwith' => '.xml',
    'tag' => 'nondeterminstic_ordering_in_gsettings_glib_enums_xml',
    'type' => 0
    ),

    'ispellhash' => array(
    'regex' => '(?P<ispellhash_r>\.\/usr\/lib\/ispell\/.*\.hash$)',
    'startwith' => './usr/lib/ispell',
    'endwith' => '.hash',
    'tag' => 'random_ispell_hash_files',
    'type' => 3
    ),

    'moarvm' => array(
    'regex' => '(?P<moarvm_r>\.\/usr\/share\/.*\.moarvm$)',
    'startwith' => './usr/share/',
    'endwith' => '.moarvm',
    'tag' => 'nondeterminstic_output_generated_by_moarvm',
    'type' => 3
    ),

    'javadoc' => array(
    'regex' => '(?P<javadoc_r>.*java\-doc)',
    'search' => 'java-doc',
    'tag' => '??javadoc_found??',
    'type' => 4
    ),


    'fontforge' => array(
    'regex' => '(?P<fontforge_r>\.\/usr\/share\/fonts\/.*\.(?:ttf|woff|otf|sfd)$)',
    'startwith' => './usr/share/fonts/',
    'endwith' => array('.ttf','.woff','.otf','.sfd'),
    'tag' => 'fontforge_resets_modification_time',
    'type' => 3
    ),
        

    'pickle' => array(
    'regex' => '(?P<pickle_r>environment\.pickle$)',
    'endwith' => 'environment.pickle',
    'tag' => 'random_order_in_python_environment_pickle',
    'type' => 2
    ),
        
        
    'edj' => array(
    'regex' => '(?P<edj_r>\.edj$)',
    'endwith' => '.edj',
    'tag' => 'timestamps_in_edj_files_generated_by_edje_cc',
    'type' => 2
    ),
        
    'mol' => array(
    'regex' => '(?P<mol_r>\.mol$)',
    'endwith' => '.mol',
    'tag' => 'timestamps_in_mdl_molfile',
    'type' => 2
    ),
                
    'sphinxsearchindex' => array(
    'regex' => '(?P<sphinxsearchindex_r>searchindex\.js$)',
    'endwith' => 'searchindex.js',
    'tag' => 'randomness_in_documentation_generated_by_sphinx',
    'type' => 2
    ),
        
    'rubysearchindex' => array(
    'regex' => '(?P<rubysearchindex_r>search_index\.js$)',
    'endwith' => 'search_index.js',
    'tag' => 'random_order_in_ruby_rdoc_indices',
    'type' => 2
    ),
    
    'gemspec' => array(
    'regex' => '(?P<gemspec_r>\.gemspec$)',
    'endwith' => '.gemspec',
    'tag' => 'fileorder_in_gemspec_files_list',
    'type' => 2
    ),
        
    'pe' => array(
    'regex' => '(?P<pe_r>\.(?:dll|exe)$)',
    'endwith' => array('.exe','.dll'),
    'tag' => '??pe_file??',
    'type' => 2
    ),

    );
	
    public function __construct()
    {
		
		$this->diffoscope_forever_detect();
		$this->unrep_packages_index();
		
        $this->issues_both_values = array(
          'shell_g'             => array(1 => $this->shell1    , 2 => $this->shell2  , '1f' => 0 , '2f' => 0),
          'kernel_g'            => array(1 => $this->kernel1   , 2 => $this->kernel2 , '1f' => 0 , '2f' => 0),
          'differentencoding_g' => array(1 => 'utf-8'          , 2 => 'us-ascii'      , '1f' => 0 , '2f' => 0),
          'username_g'          => array(1 => $this->username1 , 2 => $this->username2 , '1f' => 0 , '2f' => 0),
		  'unamem_g'            => array(1 => 'i686'           , 2 => 'x86_64' , '1f' => 0 , '2f' => 0), //i686|x86_64
        );
        // Rules Generator //
            
        $separator = '\\\\?['.implode('', $this->possible_separators).']';
        
        // PATH //
        $path1=str_replace('-', $separator, $this->path1);
        $path2=str_replace('-', $separator, $this->path2);
		
        $this->rules['recordsbuildflags'] = array(
         1 => '\-fdebug\-prefix\-map='.$path1,
         2 => '\-fdebug\-prefix\-map='.$path2,
         'global' => '(?P<recordsbuildflags_g>\-fdebug\-prefix\-map=\/?(?:'.$path1.'|'.$path2.'))',
         'capture_from_start' => false,
         'tag' => 'records_build_flags'
        );        
        
        $this->rules['path'] = array(
         1 => '(?P<path1>'.$path1.')',
         2 => '(?P<path2>'.$path2.')',
         'global' => '(?P<path_g>'.$path1.'|'.$path2.')',
         'capture_from_start' => false,
         'tag' => 'captures_build_path',
		 'subsearch' => 'recordsbuildflags'
        );
		
        $this->rules['path1'] = array(
         1 => '(?P<path1>1\.{2,3}s\.{2,3}t)',
         2 => '(?P<path2>2\.{2,3}n\.{2,3}d)', 
         'global' => '(?P<path1_g>(?:1\.{2,3}(?:s\.{2,3}t|S\.{2,3}T)|2\.{2,3}(?:n\.{2,3}d|N\.{2,3}D)))', // (?:1\.{2,3}s\.{2,3}t|2\.{2,3}n\.{2,3}d)
         'capture_from_start' => false,
		 'case_sensetive' => true,
         'tag' => '??captures_build_path_dots??'
        );
        
         // gnarwl package
         $this->rules['path2'] = array(
         1 => '(?P<path1>b_\W?u_\W?i_\W?l_\W?d_\W?-_\W?1_\W?s_\W?t)',
         2 => '(?P<path2>b_\W?u_\W?i_\W?l_\W?d_\W?-_\W?2_\W?n_\W?d)',
         'global' => '(?P<path2_g>b_\W?u_\W?i_\W?l_\W?d_\W?-_\W?(?:1_\W?s_\W?t|2_\W?n_\W?d))',
         'capture_from_start' => false,
         'tag' => 'captures_build_path'
         );
		 
		 /* 
		 // RPM - https://tests.reproducible-builds.org/rpms/fedora-23/x86_64/xorg-x11-xsm/xorg-x11-xsm-1.0.2-25.fc23.src.rpm.html
         $this->rules['rpmheader'] = array(
         1 => '(?P<rpmheader1>HEADERIMMUTABLE: 000)',
         2 => '(?P<rpmheader2>HEADERIMMUTABLE: 000)',
         'global' => '(?P<rpmheader_g>HEADERIMMUTABLE: 000)',
         'capture_from_start' => false,
         'tag' => '??rpm_header_diffs??'
         );
		 */
		 
		 /*
		 
		 https://tests.reproducible-builds.org/archlinux/extra/kdeaccessibility-kmousetool/kdeaccessibility-kmousetool-15.08.3-2-x86_64.pkg.tar.xz.html
		 https://tests.reproducible-builds.org/archlinux/extra/kdeaccessibility-kmouth/kdeaccessibility-kmouth-15.08.3-2-x86_64.pkg.tar.xz.html
		 
         $this->rules['idmnumber'] = array(
         1 => '(?P<idmnumber1>name="idm\d{8,20}")',
         2 => '(?P<idmnumber2>name="idm\d{8,20}")',
         'global' => '(?P<idmnumber_g>name="idm\d{8,20}")',
         'capture_from_start' => false,
         'tag' => '??idm_number??'
         );	
		 
		 */
		 
		 // https://tests.reproducible-builds.org/netbsd/dbd/amd64/binary/sets/base.tgz.html
         $this->rules['jobscountdiff'] = array(
         1 => '(?P<rpmheader1> \-j \d+ )',
         2 => '(?P<rpmheader2> \-j \d+ )',
         'global' => '(?P<rpmheader_g> \-j \d+ )',
         'capture_from_start' => false,
         'tag' => '??jobs_count_diff??'
         );
		 
           // HOMEDIR //
        $homedir1=str_replace('-', $separator, $this->homedir1);
        $homedir2=str_replace('-', $separator, $this->homedir2);
    // FP in mrmpi "To do this, first build the dummy MPI"
        $this->rules['homedir'] = array(
         1 => '(?P<homedir1>'.$homedir1.')',
         2 => '(?P<homedir2>'.$homedir2.')',
         'global' => '(?P<homedir_g>'.$homedir1.'|'.$homedir2.')',
         'capture_from_start' => false,
         'tag' => 'captures_home_dir'
        );
        $this->rules['homedirorgecos'] = array(
         1 => '(?P<homedirorgecos1>build\-first)',
         2 => '(?P<homedirorgecos2>build\-second)',
         'global' => '(?P<homedirorgecos_g>build\-first|build\-second)',
         'capture_from_start' => false,
         'tag' => '??captures_home_dir_or_GECOS??'
        );
    // SHELL //
        
        $shell1=preg_quote($this->shell1, '/');
        $shell2=preg_quote($this->shell2, '/');
        $this->rules['shell'] = array(
         1 => '(?P<shell1>'.$shell1.')',
         2 => '(?P<shell2>'.$shell2.')',
         'global' => '(?P<shell_g>'.$shell1.'|'.$shell2.')',
         'capture_from_start' => false,
         'tag' => 'captures_shell_variable_in_autofoo_script'
        );
    // KERNEL //

        $kernel1=preg_quote($this->kernel1, '/');
        $kernel2=preg_quote($this->kernel2, '/');
        $this->rules['kernel'] = array(
         1 => '(?P<kernel1>'.$kernel1.')',
         2 => '(?P<kernel2>'.$kernel2.')',
         'global' => '(?:[^.|\d](?P<kernel_g>'.$kernel1.'|'.$kernel2.')[^\w\d])',
         'capture_from_start' => false,
         'tag' => 'captures_kernel_version'
        );
    // HOSTNAME //

        $hostname1=implode('|', str_replace('-', $separator, $this->hostnames1));
        $hostname2=str_replace('-', $separator, $this->hostname2);
        $this->rules['hostname'] = array(
         1 => '(?P<hostname_1>'.$hostname1.')',
         2 => '(?P<hostname_2>'.$hostname2.')',
         'global' => '(?P<hostname_g>'.$hostname1.'|'.$hostname2.')',
         'capture_from_start' => false,
         'tag' => 'user_hostname_manually_added_requiring_further_investigation'
        );
    // UNAME -a //
    
        $this->rules['uname'] = array(
         1 => '(?P<uname1>Linux '.$hostname1.' '.$kernel1.')',
         2 => '(?P<uname2>Linux '.$hostname2.' '.$kernel2.')',
         'global' => '(?P<uname_g>Linux (?:'.$hostname1.'|'.$hostname2.') (?:'.$kernel1.'|'.$kernel2.'))',
         'capture_from_start' => false,
         'tag' => '??uname-a??'
        );
        
        $this->rules['copyright1'] = array(
         1 => '(?P<copyright1_1>&#169; (?:Copyright ){1,2}\d{4}\–\d{4})',
         2 => '(?P<copyright1_2>&#169; (?:Copyright ){1,2}\d{4}\–\d{4})',
         'global' => '(?P<copyright1_g>&#169; (?:Copyright ){1,2}\d{4}\–\d{4})',
         'capture_from_start' => false,
         'tag' => '??copyright1??'
        );
    // Domain Name //

        $domainname1=preg_quote($this->domainname1, '/');
        $domainname2=str_replace('-', $separator, $this->domainname2);
        $this->rules['domainname'] = array(
         1 => '(?P<domainname_1>'.$domainname1.')',
         2 => '(?P<domainname_2>'.$domainname2.')',
         'global' => '(?P<domainname_g>'.$domainname2.')',
         'capture_from_start' => false,
         'tag' => '???missing_domain_name_issue???'
        );
    // Enviroment //

        // todo: handle if | in separator.
        $env_var=str_replace(' ', $separator, implode('|', $this->env_var));
        $this->rules['env_var'] = array(
         2 => '(?P<env_var_2>'.$env_var.')',
         'global' => '(?P<env_var_g>'.$env_var.')',
         'capture_from_start' => false,
         'tag' => '??missing env capture??'
        );
    // TODO: DISABLE
        // Generic
        $this->rules['gmt'] = array(
         1 => '(?P<gmt_1>GMT'.$separator.'?'.preg_quote($this->GMT1, '/').')',
         2 => '(?P<gmt_2>GMT'.$separator.'?'.preg_quote($this->GMT2, '/').')',
         'global' => '(?P<gmt_g>GMT'.$separator.'?(?:'.preg_quote($this->GMT1, '/').'|'.preg_quote($this->GMT2, '/').'))',
         'capture_from_start' => false,
         'tag' => '??GMT TZ??'
        );
    // PATH Enviroment //
        
        $pathenv=preg_quote($this->pathenv, '/');
        $this->rules['pathenv'] = array(
         2 => '(?P<pathenv_2>'.$pathenv.')',
         'global' => '(?P<pathenv_g>'.$pathenv.')',
         'capture_from_start' => false,
         'tag' => 'leaks_path_environment_variable'
        );
    // too much input ERROR //

        $toomuchinput='\[ Too much input for diff \(SHA1: [a-fA-F0-9]{40}\) \]';
        $this->rules['toomuchinput'] = array(
         1 => '(?P<toomuchinput1>'.$toomuchinput.')',
         2 => '(?P<toomuchinput2>'.$toomuchinput.')',
         'global' => '(?P<toomuchinput_g>'.$toomuchinput.')',
         'capture_from_start' => true,
         'tag' => 'too_much_input_for_diff'
        );
        $GECOS1=implode('|', str_replace(' ', $separator, $this->GECOS1));
        $GECOS2=implode('|', str_replace(' ', $separator, $this->GECOS2));
        $this->rules['gecos'] = array(
         1 => '(?P<gecos1>'.$GECOS1.')',
         2 => '(?P<gecos2>'.$GECOS2.')',
         'global' => '(?P<gecos_g>'.$GECOS1.'|'.$GECOS2.')',
         'capture_from_start' => false,
         'tag' => 'captures_users_gecos'
        );
    // pdfid
        $pdfid='\/ID \[\<[a-fA-F0-9]{32}\> \<[a-fA-F0-9]{32}\>\]';
        $this->rules['pdfid'] = array(
         1 => '(?P<pdfid_1>'.$pdfid.')',
         2 => '(?P<pdfid_2>'.$pdfid.')',
         'global' => '(?P<pdfid_g>'.$pdfid.')',
         'capture_from_start' => true,
         'tag' => 'random_id_in_pdf_generated_by_dblatex',
		 'codesearch' => 'pdflatex'
        );
        $pdfidbinary='\/ID \[(?:\(.{10,50}\)\s?){2}\]';
        $this->rules['pdfidbinary'] = array(
         1 => '(?P<pdfidbinary_1>'.$pdfidbinary.')',
         2 => '(?P<pdfidbinary_2>'.$pdfidbinary.')',
         'global' => '(?P<pdfidbinary_g>'.$pdfidbinary.')',
         'capture_from_start' => true,
         'tag' => '??pdfidbinary??'
        );
		
        $buildid='    Build ID: [0-9a-f]{40}$';
        $this->rules['buildid'] = array(
         1 => '(?P<buildid_1>'.$buildid.')',
         2 => '(?P<buildid_2>'.$buildid.')',
         'global' => '(?P<buildid_g>'.$buildid.')',
         'capture_from_start' => true,
         'tag' => 'build_id_variation_requiring_further_investigation'
        );
        $this->rules['htmlenc'] = array(
         1 => '(?P<htmlenc_1>&#\d{2};&#x\d{2};)',
         2 => '(?P<htmlenc_2>&#\d{2};&#x\d{2};)',
         'global' => '(?P<htmlenc_g>'.'&#\d{2};&#x\d{2};)',
         'capture_from_start' => false,
         'tag' => '??html encoded attribute??'
        );
        $this->rules['gendateissuetagmeta'] = array(
         1 => '(?P<gendateissuetagmeta1><meta name="date" content="\d{4}\-\d{1,2}\-\d{1,2}">)',
         2 => '(?P<gendateissuetagmeta2><meta name="date" content="\d{4}\-\d{1,2}\-\d{1,2}">)',
         'global' => '(?P<gendateissuetagmeta_g><meta name="date" content="\d{4}\-\d{1,2}\-\d{1,2}">)',
         'capture_from_start' => true,
         'tag' => '??date-issue-tag1??'
        );
    // TODO use from date1/2
        $this->rules['datepoch'] = array(
         1 => '(?P<datepoch1>1472\d{6})',
         2 => '(?P<datepoch2>1506\d{6})',
         'global' => '(?P<datepoch_g>(?:1506|1472)\d{6})',
         'capture_from_start' => false,
         'tag' => '??datepoch??'
        );
        $this->rules['timestampbnd'] = array(
         1 => '(?P<timestampbnd1>Bnd\-LastModified: 1472\d{6})',
         2 => '(?P<timestampbnd2>Bnd\-LastModified: 1506\d{6})',
         'global' => '(?P<timestampbnd_g>Bnd\-LastModified: (?:1506|1472)\d{6})',
         'capture_from_start' => true,
         'tag' => 'timestamp_in_java_bnd_manifest'
        );
        $this->rules['inceptiondate'] = array(
         1 => '(?P<inceptiondate1>Inception\-Date: \d{4}:\d{1,2}:\d{1,2})',
         2 => '(?P<inceptiondate2>Inception\-Date: \d{4}:\d{1,2}:\d{1,2})',
         'global' => '(?P<inceptiondate_g>Inception\-Date: \d{4}:\d{1,2}:\d{1,2})',
         'capture_from_start' => true,
         'tag' => '??inception-date??'
        );
        $this->rules['dateobj'] = array(
         1 => '(?P<dateobj1>    \'date\': \'\d{4}\-\d{1,2}\-\d{1,2}\',)',
         2 => '(?P<dateobj2>    \'date\': \'\d{4}\-\d{1,2}\-\d{1,2}\',)',
         'global' => '(?P<dateobj_g>    \'date\': \'\d{4}\-\d{1,2}\-\d{1,2}\',)',
         'capture_from_start' => true,
         'tag' => '??dateobj??'
        );
    // UMASK //

        $this->rules['umask'] = array(
         1 => '(?P<umask1>drwxr-xr-x|-rw-r--r--|-rwxr-xr-x)',
         2 => '(?P<umask2>drwxrwxr-x|-rw-rw-r--|-rwxrwxr-x)',
         'global' => '(?P<umask_g>drwxrwxr-x|-rw-rw-r--|-rwxrwxr-x)',
         'capture_from_start' => true,
         'tag' => 'different_due_to_umask'
        );

		// https://tests.reproducible-builds.org/debian/rb-pkg/unstable/armhf/golang-github-appc-cni.html

        $this->rules['umaskx'] = array(
         1 => '(?P<umaskx1>drwxr--r--|-rw-r--r--|-rwxr--r--)',
         2 => '(?P<umaskx2>drwxr-xr-x|-rwxr-xr-x|-rwxr-xr-x)',
         'global' => '(?P<umaskx_g>drwxr-xr-x|-rwxr-xr-x|-rwxr-xr-x)',
         'capture_from_start' => true,
         'tag' => 'execute_prem_different_due_to_umaskx'
        );
		
         $this->rules['umasknumeric'] = array(
         1 => '(?P<umasknumeric1> 100644 )',
         2 => '(?P<umasknumeric2> 100664 )',
         'global' => '(?P<umasknumeric_g> (?:100644|100664) )',
         'capture_from_start' => false,
         'tag' => 'different_due_to_umask'
         );
        
        $gzipdict='gzip compressed data, extra';
        $this->rules['gzipdict'] = array(
         1 => '(?P<gzipdict1>'.$gzipdict.')',
         2 => '(?P<gzipdict2>'.$gzipdict.')',
         'global' => '(?P<gzipdict_g>'.$gzipdict.')',
         'capture_from_start' => true,
         'tag' => 'timestamps_in_dictionaries'
        );
        $gzip='gzip compressed data, (?:was|last)';
        $this->rules['gzip'] = array(
         1 => '(?P<gzip1>'.$gzip.')',
         2 => '(?P<gzip2>'.$gzip.')',
         'global' => '(?P<gzip_g>'.$gzip.')',
         'capture_from_start' => true,
         'tag' => 'timestamps_in_gzip_headers'
        );
        
        
        $gcj = 'fbootclasspath=\.\/:\/usr\/share\/java\/libgcj\-\d\.jar';
        $this->rules['gcj'] = array(
         1 => '(?P<gcj1>'.$gcj.')',
         2 => '(?P<gcj2>'.$gcj.')',
         'global' => '(?P<gcj_g>'.$gcj.')',
         'capture_from_start' => false,
         'tag' => 'randomness_in_gcj_output'
        );
		
        $peres = 'Resource Table: 0x[0-9a-f]{8} 0x[0-9a-f]{8}';
        $this->rules['peres'] = array(
         1 => '(?P<peres1>'.$peres.')',
         2 => '(?P<peres2>'.$peres.')',
         'global' => '(?P<peres_g>'.$peres.')',
         'capture_from_start' => false,
         'tag' => '??diff_in_pe_binaries??'
        );

        $this->rules['petimestamp'] = array(
         1 => '(?P<petimestamp1>Time stamp: 0x[0-9a-f]{8})',
         2 => '(?P<petimestamp2>Time stamp: 0x[0-9a-f]{8})',
         'global' => '(?P<petimestamp_g>Time stamp: 0x[0-9a-f]{8})',
         'capture_from_start' => false,
         'tag' => 'timestamps_in_pe_binaries'
        );

		
    // ENCODING //
        
        $different_encoding1='utf\-8';
        $different_encoding2='us\-ascii';
        $this->rules['differentencoding'] = array(
         1 => '(?P<differentencoding1>'.$different_encoding1.'$)',
         2 => '(?P<differentencoding2>'.$different_encoding2.'$)',
         'global' => '(?P<differentencoding_g>(?:'.$different_encoding1.'|'.$different_encoding2.')$)',
         'capture_from_start' => true, // (false = catch more instances [in HTML], may be FP) , true = no FP
         'tag' => 'different_encoding'
        );
    // EXecution Time //
        $this->rules['executiontime1'] = array(
         1 => '(?P<executiontime1_1>'.'started: {0,2}\d{2}'.')',
         2 => '(?P<executiontime1_2>'.'finished: {0,2}\d{2}'.')',
         'global' => '(?P<executiontime1_g>'.'(?:started|finished): {0,2}\d{1,2}'.')',
         'capture_from_start' => true,
         'tag' => 'captures_execution_time'
        );
        $this->rules['executiontime2'] = array(
         1 => '(?P<executiontime2_1>'.'Tests completed in \d{1,2}'.')',
         2 => '(?P<executiontime2_2>'.'Tests completed in \d{1,2}'.')',
         'global' => '(?P<executiontime2_g>'.'Tests completed in \d{1,2}'.')',
         'capture_from_start' => true,
         'tag' => 'captures_execution_time'
        );
    // USERNAME //

        $this->rules['username'] = array(
         1 => '(?P<username1>'.$this->username1.')',
         2 => '(?P<username2>'.$this->username2.')',
         'global' => '(?P<username_g>'.$this->username1.'|'.$this->username2.')',
         'capture_from_start' => false,
         'tag' => 'user_hostname_manually_added_requiring_further_investigation'
        );
        $this->rules['usernamemanifest'] = array(
         1 => '(?P<usernamemanifest1>Created-By: '.$this->username1.')',
         2 => '(?P<usernamemanifest2>Created-By: '.$this->username2.')',
         'global' => '(?:(?:Built(?:\-By)?|Created\-By|Implementation\-Version|Implementation\-Vendor): ' .
            '(?P<usernamemanifest_g>'.$this->username1.'|'.$this->username2.'))',
         'capture_from_start' => true,
         'tag' => 'user_in_java_jar_manifest'
        );
		
		/*
        Change to use
        $this->date1
        $this->date2
        */

        
        date_default_timezone_set('UTC');
        $timestamp1 = mktime(0, 0, 0, $this->month1, $this->day1, $this->year1);
        $timestamp2 = mktime(0, 0, 0, $this->month2, $this->day2, $this->year2);
        $year1=date('Y', $timestamp1);
        $year2=date('Y', $timestamp2);
        
        $date1 = getdate($timestamp1);
        $date2 = getdate($timestamp2);
        
		//TODO: use date vars
		$date_var1_1='20160829|08292016|29082016';
        $date_var1_2='20171002|10022017|02102017';	
		
        $monthshort1=date('M', $timestamp1);
        $monthshort2=date('M', $timestamp2);
        $date_var2_1='\d{2}\-['.$monthshort1.'|'.$monthshort2.']\-\d{2} \d{2}:\d{2}';
        $date_var2_2='\d{2}\-['.$monthshort1.'|'.$monthshort2.']\-\d{2} \d{2}:\d{2}';
        $year1='[ ->]'.$date1['year'].'(?:[,<: \-\/]|$)';
        $year2='[ ->]'.$date2['year'].'(?:[,<: \-\/]|$)';
        $this->rules['date_var1'] = array(
         1 => '(?P<date_var1_1>'.$date_var1_1.')',
         2 => '(?P<date_var1_2>'.$date_var1_2.')',
         'global' => '(?P<date_var1_g>'.$date_var1_1.'|'.$date_var1_2.')',
         'capture_from_start' => false,
         'tag' => '??date-1??'
        );
        $this->rules['date_var2'] = array(
         1 => '(?P<date_var2_1>'.$date_var2_1.')',
         2 => '(?P<date_var2_2>'.$date_var2_2.')',
         'global' => '(?P<date_var2_g>'.'\d{2}\-['.$monthshort1.'|'.$monthshort2.']\-\d{2} \d{2}:\d{2}'.')',
         'capture_from_start' => false,
         'tag' => '??date-2??'
        );
		
		// TODO: disable [generic, FPs]
        $this->rules['day'] = array(
         1 => '(?P<day1>'.$date1['weekday'].')',
         2 => '(?P<day2>'.$date2['weekday'].')',
         'global' => '(?P<day_g>'.$date1['weekday'].'|'.$date1['weekday'].')',
         'capture_from_start' => false,
         'tag' => '??general-day??'
        );
        $this->rules['timestampsdvilatex'] = array(
         1 => '(?P<timestampsdvilatex1>TeX output '.$date1['year'].'\.$)',
         2 => '(?P<timestampsdvilatex2>TeX output '.$date2['year'].'\.$)',
         'global' => '(?P<timestampsdvilatex_g>TeX output (?:'.$date1['year'].'|'.$date2['year'].')\.$)',
         'capture_from_start' => true,
         'tag' => 'timestamps_in_dvi_generated_by_latex'
        );
		
		//Maybe timestamps_in_tex_documents?
        $this->rules['timestampspdflatextex'] = array(
         1 => '(?P<timestampspdflatextex1>\/Creator \( TeX output '.$date1['year'].')',
         2 => '(?P<timestampspdflatextex2>\/Creator \( TeX output '.$date2['year'].')',
         'global' => '(?P<timestampspdflatextex_g>\/Creator \( TeX output (?:'.$date1['year'].'|'.$date2['year'].'))',
         'capture_from_start' => true,
         'tag' => '??timestamps_in_pdf_generated_by_latex_or_timestamps_in_tex_documents??'
        );
        $this->rules['timestampspdflatex'] = array(
         1 => '(?P<timestampspdflatex1>\/(?:CreationDate|ModDate) \( D:\d{14})',
         2 => '(?P<timestampspdflatex2>\/(?:CreationDate|ModDate) \( D:\d{14})',
         'global' => '(?P<timestampspdflatex_g>\/(?:CreationDate|ModDate) \( D:\d{14})',
         'capture_from_start' => true,
         'tag' => 'timestamps_in_pdf_generated_by_latex',
		 'codesearch' => 'pdflatex'
        );
        $this->rules['ocamlpptmprand'] = array(
         1 => '(?P<ocamlpptmprand1>\/tmp\/ocamlpp[0-9a-zA-Z]{6})',
         2 => '(?P<ocamlpptmprand2>\/tmp\/ocamlpp[0-9a-zA-Z]{6})',
         'global' => '(?P<ocamlpptmprand_g>\/tmp\/ocamlpp[0-9a-zA-Z]{6})',
         'capture_from_start' => false,
         'tag' => 'randomness_in_ocaml_preprocessed_files'
        );
        $this->rules['fatlto'] = array(
         1 => '(?P<fatlto1>\.gnu\.lto_\.inline\.[0-9a-f]{16} )',
         2 => '(?P<fatlto2>\.gnu\.lto_\.inline\.[0-9a-f]{16} )',
         'global' => '(?P<fatlto_g>\.gnu\.lto_\.inline\.[0-9a-f]{16} )',
         'capture_from_start' => false,
         'tag' => 'randomness_in_fat_lto_objects'
        );
        $this->rules['timestampspng'] = array(
         1 => '(?P<timestampspng1> {4}text: "\d{4}\-\d{1,2}\-\d{1,2}T\d{1,2}:)',
         2 => '(?P<timestampspng2> {4}text: "\d{4}\-\d{1,2}\-\d{1,2}T\d{1,2}:)',
         'global' => '(?P<timestampspng_g> {4}text: "\d{4}\-\d{1,2}\-\d{1,2}T\d{1,2}:)',
         'capture_from_start' => true,
         'tag' => 'timestamps_in_png'
        );
        $this->rules['emacsautoloads'] = array(
         1 => '(?P<emacsautoloads1>;{6} {2}"\w+\-\w+\.el"\) \(\d{1,8} \d{1,8} \d{1,8} \d{1,8}\)\))',
         2 => '(?P<emacsautoloads2>;{6} {2}"\w+\-\w+\.el"\) \(\d{1,8} \d{1,8} \d{1,8} \d{1,8}\)\))',
         'global' => '(?P<emacsautoloads_g>;{6} {2}(?:((?:"(?:\w+\-?)+\.el" ?){1,9}\) ' .
            '\(\d{1,8} \d{1,8})|\(\d{1,8} \d{1,8} \d{1,8} \d{1,8}))',
         'capture_from_start' => true,
         'tag' => 'timestamps_in_emacs_autoloads'
        );
        $this->rules['emacsautoloads1'] = array(
         1 => '(?P<emacsautoloads1_1>;{3}#{3} \(autoloads nil "\w+)', 
         2 => '(?P<emacsautoloads1_2>;{3}#{3} \(autoloads nil "\w+)',
         'global' => '(?P<emacsautoloads1_g>;{3}#{3} \(autoloads nil "\w+)',
         'capture_from_start' => true,
         'tag' => 'timestamps_in_emacs_autoloads'
        );
        $this->rules['uidgid'] = array(
         1 => '(?P<uidgid1>\d {5}1111 {5}1111\s+)',
         2 => '(?P<uidgid2>\d {5}2222 {5}2222\s+)',
         'global' => '(?P<uidgid_g>\d {5}2222 {5}2222\s+)',
         'capture_from_start' => false,
         'tag' => '??users_and_groups_in_cpio_archive??OR-Other-UID-GID??'
        );
        $this->rules['timestampsdatallegro'] = array(
         1 => '(?P<timestampsdatallegro1>DATE\.\.\.\.\d{1,2}\-\d{1,2}\-\d{3,4})',
         2 => '(?P<timestampsdatallegro2>DATE\.\.\.\.\d{1,2}\-\d{1,2}\-\d{3,4})',
         'global' => '(?P<timestampsdatallegro_g>DATE\.\.\.\.\d{1,2}\-\d{1,2}\-\d{3,4})',
         'capture_from_start' => false,
         'tag' => 'timestamps_in_allegro_dat_files'
        );
        $this->rules['ocamlproviders'] = array(
         1 => '(?P<ocamlproviders1>Provides: lib\w+\-ocaml\-dev\-\w{5})',
         2 => '(?P<ocamlproviders2>Provides: lib\w+\-ocaml\-dev\-\w{5})',
         'global' => '(?P<ocamlproviders_g>Provides: lib\w+\-ocaml\-dev\-\w{5})',
         'capture_from_start' => true,
         'tag' => 'randomness_in_ocaml_provides'
        );
        $this->rules['installtimestamp'] = array(
         1 => '(?P<installtimestamp1> install_time="\d{13" )',
         2 => '(?P<installtimestamp2> install_time="\d{13" )',
         'global' => '(?P<installtimestamp_g> install_time="\d{13" )',
         'capture_from_start' => false,
         'tag' => '??installtimestamp??'
        );
        $this->rules['fontsinpdf'] = array(
         1 => '(?P<fontsinpdf1>\/(?:BaseFont|FontName) \/[A-Z]{6}\+)',
         2 => '(?P<fontsinpdf2>\/(?:BaseFont|FontName) \/[A-Z]{6}\+)',
         'global' => '(?P<fontsinpdf_g>\/(?:BaseFont|FontName) \/[A-Z]{6}\+)',
         'capture_from_start' => true,
         'tag' => 'fonts_in_pdf_files'
        );
        $this->rules['pythonversionnumber'] = array(
         1 => '(?P<pythonversionnumber1>Version: (?:\d\.){1,5}dev201[6|7]\d{4})',
         2 => '(?P<pythonversionnumber2>Version: (?:\d\.){1,5}dev201[6|7]\d{4})',
         'global' => '(?P<pythonversionnumber_g>Version: (?:\d\.){1,5}dev201[6|7]\d{4})',
         'capture_from_start' => false,
         'tag' => 'timestamps_in_python_version_numbers'
        );
        $this->rules['adalibts'] = array(
         1 => '(?P<adalibts1>D [\w\-_]+\.ad(?:s|b)\t{1,5}\d{14} [0-9a-f]{8} [\w\.\-_]+%s)',
         2 => '(?P<adalibts2>D [\w\-_]+\.ad(?:s|b)\t{1,5}\d{14} [0-9a-f]{8} [\w\.\-_]+%s)',
         'global' => '(?P<adalibts_g>D [\w\-_]+\.ad(?:s|b)\t{1,5}\d{14} [0-9a-f]{8} [\w\.\-_]+%s)',
         'capture_from_start' => true,
         'tag' => 'timestamps_in_ada_library_information_files'
        );
        $this->rules['tsman0'] = array(
         1 => '(?:.TH [\w_\-\.]+ (?P<tsman0>"\d" "(?:October|August) 201[6|7]"))',
         2 => '(?:.TH [\w_\-\.]+ (?P<tsman0>"\d" "(?:October|August) 201[6|7]"))',
         'global' => '(?:.TH [\w_\-\.]+ (?P<tsman0_g>"\d" "(?:October|August) 201[6|7]"))',
         'capture_from_start' => true,
         'tag' => '??tsman0??'
        );
        $this->rules['tsman1'] = array(
         1 => '(?:.TH [\w_\-\.]+ (?P<tsman1_1>\d 201[6|7]\\\\))',
         2 => '(?:.TH [\w_\-\.]+ (?P<tsman1_2>\d 201[6|7]\\\\))',
         'global' => '(?:.TH [\w_\-\.]+ (?P<tsman1_g>\d 201[6|7]\\\\))',
         'capture_from_start' => true,
         'tag' => '??tsman1??'
        );
        $this->rules['tsman2'] = array(
         1 => '(?:.TH [\w_\-\.]+ (?P<tsman2>\d\w{1,2} "\w+ \d+, \d{4}"))',
         2 => '(?:.TH [\w_\-\.]+ (?P<tsman2>\d\w{1,2} "\w+ \d+, \d{4}"))',
         'global' => '(?:.TH [\w_\-\.]+ (?P<tsman2_g>\d\w{1,2} "\w+ \d+, \d{4}"))',
         'capture_from_start' => true,
         'tag' => '??tsman2??'
        );
        $this->rules['tsman3'] = array(
         1 => '(?:.TH [\w_\-\.]+ (?P<tsman3>"\d" "201[6|7]\-\d{2}\-{2}"))',
         2 => '(?:.TH [\w_\-\.]+ (?P<tsman3>"\d" "201[6|7]\-\d{2}\-{2}"))',
         'global' => '(?:.TH [\w_\-\.]+ (?P<tsman3_g>"\d" "201[6|7]\-\d{2}\-{2}"))',
         'capture_from_start' => true,
         'tag' => '??tsman3??'
        );
        $this->rules['tsman4'] = array(
         1 => '(?:.TH [\w_\-\.]+ (?P<tsman4>\d "201[6|7]\-\d{1,2}\-{1,2}"))',
         2 => '(?:.TH [\w_\-\.]+ (?P<tsman4>\d "201[6|7]\-\d{1,2}\-{1,2}"))',
         'global' => '(?:.TH [\w_\-\.]+ (?P<tsman4_g>\d "201[6|7]\-\d{1,2}\-{1,2}"))',
         'capture_from_start' => true,
         'tag' => '??tsman4??'
        );
        $this->rules['tsman5'] = array(
         1 => '(?:.TH [\w_\-\.]+ (?P<tsman5>\d 201[6|7]\-))',
         2 => '(?:.TH [\w_\-\.]+ (?P<tsman5>\d 201[6|7]\-))',
         'global' => '(?:.TH [\w_\-\.]+ (?P<tsman5_g>\d 201[6|7]\-))',
         'capture_from_start' => true,
         'tag' => '??tsman5??'
        );
        $this->rules['tsman6'] = array(
         1 => '(?:.TH [\w_\-\.]+ (?P<tsman6>.TH [\w_\-\.]+ \d "\d{1,2} \w+ \d{4}"))',
         2 => '(?:.TH [\w_\-\.]+ (?P<tsman6>.TH [\w_\-\.]+ \d "\d{1,2} \w+ \d{4}"))',
         'global' => '(?:.TH [\w_\-\.]+ (?P<tsman6_g>.TH [\w_\-\.]+ \d "\d{1,2} \w+ \d{4}"))',
         'capture_from_start' => true,
         'tag' => '??tsman6??'
        );
        $this->rules['tsman7'] = array(
         1 => '(?:.TH (?P<tsman7>"[\w_\-\.]+" \d "(?:September|August) 201[6|7]"))',
         2 => '(?:.TH (?P<tsman7>"[\w_\-\.]+" \d "(?:September|August) 201[6|7]"))',
         'global' => '(?:.TH (?P<tsman7_g>"[\w_\-\.]+" \d "(?:September|August) 201[6|7]"))',
         'capture_from_start' => true,
         'tag' => '??tsman7??'
        );
        $this->rules['tsman8'] = array(
         1 => '(?:.TH (?P<tsman8>"[\w_\-\.]+" "\d" "201[6|7]\\\\))',
         2 => '(?:.TH (?P<tsman8>"[\w_\-\.]+" "\d" "201[6|7]\\\\))',
         'global' => '(?:.TH (?P<tsman8_g>"[\w_\-\.]+" "\d" "201[6|7]\\\\))',
         'capture_from_start' => true,
         'tag' => '??tsman8??'
        );

        // poti package
        /*
        +    ¦   ¦   ¦   ¦ -#define POTI_GITVERSION "/bin/sh: 1: git: not found"
        +    ¦   ¦   ¦   ¦ -#define POTI_GITDATE "/bin/sh: 1: git: not found"
        +    ¦   ¦   ¦   ¦ +#define POTI_GITVERSION "/bin/sh: git : commande introuvable"
        +    ¦   ¦   ¦   ¦ +#define POTI_GITDATE "/bin/sh: git : commande introuvable"
        */

        $this->rules['missingdepend'] = array(
         1 => ': not found',
         2 => ': commande introuvable',
         'global' => '(?P<missingdepend_g>: commande introuvable)', // : (?:not found| commande introuvable)
         'capture_from_start' => false,
         'tag' => '??missing_executable_file??'
        );

        $this->rules['missingdepend1'] = array(
         1 => 'No such file or directory',
         2 => 'No such file or directory',
         'global' => '(?P<missingdepend1_g>No such file or directory)', 
         'capture_from_start' => false,
         'tag' => '??missing_executable_file_nf??'
        );
		
		
        $this->rules['buildpathptex'] = array(
         1 => '\/PTEX\.FileName '.$path1,
         2 => '\/PTEX\.FileName '.$path2,
         'global' => '(?P<buildpathptex_g>\/PTEX\.FileName (?:'.$path1.'|'.$path2.'))',
         'capture_from_start' => false,
         'tag' => '??build_path_in_PTEX??'
        );
		
        $this->rules['unamem'] = array(
         1 => 'x86_64',
         2 => 'i686',
         'global' => '(?P<unamem_g>(?:i686|x86_64))',
         'capture_from_start' => false,
         'tag' => 'captures_build_arch'
        );
		
    // END GLOBAL RULES //
    }

	public function diffoscope_forever_detect() {
		$break = 'https://tests.reproducible-builds.org/debian/index_breakages.html';
		$breakages_backup = 'breakages.dump';
		
		if(!$this->UseBackUpBreakages) {
			echo 'Downloading: ' , $break , "\n";
			$fc = file_get_contents($break);
			if($fc === false) {
				echo 'Error downloading: ', $break , "\n";
				return;
			} else {
				echo 'Saving to: ' , $breakages_backup , "\n";
				file_put_contents($breakages_backup, $fc, LOCK_EX);
			}
		} else {
			$fc = file_get_contents($breakages_backup);
		}
		
		if($fc === false) {
			return;
		}		
		
		$forever_start   = strpos($fc, 'are marked as unreproducible, but their diffoscope output does not seem to be an html file - so probably diffoscope ran into a timeout:');
		$forever_end     = strpos($fc , '</pre>' , $forever_start);
		$forever_content = substr($fc, $forever_start , $forever_end - $forever_start );
			
		$forever_listing = strip_tags($forever_content);
		$lines = explode("\n",$forever_listing);
		foreach($lines as $line) {
			
			if(preg_match('/  (\S+) .{2,35} ('.implode('|',$this->envs).')\/('.implode('|',$this->archs).')/' , $line , $matches) !== false && !empty($matches)) {
				
				$packageName = $matches[1];
				$relatedEnv  = $matches[2];
				$relatedArch = $matches[3];
				
				if($this->BypassTestingEnv && $relatedEnv === 'testing') {
					continue;
				}
				
				if (!in_array($packageName, $this->diffoscope_forever_arr)) {
					$this->diffoscope_forever_arr[$packageName] = array();
				}
				if(!isset($this->diffoscope_forever_arr[$packageName][$relatedEnv])) {
					$this->diffoscope_forever_arr[$packageName][$relatedEnv] = array();
				}
				$this->diffoscope_forever_arr[$packageName][$relatedEnv][]=$relatedArch;
				
			}
			
		}
		
	}
	
	
		
	public function unrep_packages_index() {
		
		$MissingIndex = false;
		$IndexesFromNet = false;
		$MissingNetIndex = false;
		/*
		Note Relevant TODO:
		
		reproducible icon 1062 packages (4.2%) failed to build from source in total unstable/armhf:
		reproducible icon 465 (1.8%) source packages failed to satisfy their build-dependencies:
		reproducible icon 123 (0.5%) packages are blacklisted and will not be tested in unstable/armhf:
		reproducible icon 18372 (72.9%) packages successfully built reproducibly in unstable/armhf:
		reproducible icon 296 (1.2%) packages which should not be build in unstable/armhf:

		*/
		
		foreach($this->envs as $env) {
			if($this->BypassTestingEnv && $env === 'testing') {
				continue;
			}
			foreach($this->archs as $arch) {
				$platform = $env.'/'.$arch;
				$packages_index = 'https://tests.reproducible-builds.org/debian/'.$platform.'/index_all_abc.html';
				$packages_index_backup = 'packages-'.$env.'-'.$arch.'.dump';
				if(!$this->UseBackUpPackages) {
					$IndexesFromNet = true;
					echo 'Downloading: ' , $packages_index , "\n";
					$fc = file_get_contents($packages_index);
					if($fc === false) {
						$MissingNetIndex = true;
						echo 'Error downloading: ', $packages_index, "\n";
						$fc = file_get_contents($packages_index_backup);
					} else { // backup
						echo 'Saving to: ' , $packages_index_backup , "\n";
						file_put_contents($packages_index_backup, $fc, LOCK_EX);
					}
				} else {
					$fc = file_get_contents($packages_index_backup);
				}
				
				if($fc === false) {
					$MissingIndex = true;
				}
							
					$packages_start       = strpos($fc, ' failed to build reproducibly in total in '.$platform.':');
					$packages_end         = strpos($fc , '</code>' , $packages_start);
					$all_packages_content = substr($fc, $packages_start , $packages_end - $packages_start );
					
					$packages_listing = strip_tags($all_packages_content);
					$lines = explode("\n",$packages_listing);
					foreach($lines as $line) {
						
						if(preg_match('/\s+(\S+)/' , $line , $matches) !== false && !empty($matches)) {
							$packageName = rtrim($matches[1] , 'P#+');

							if (!isset($this->packages_index_arr[$packageName])) {
								$this->packages_index_arr[$packageName] = array();
								$this->packages_index_arr[$packageName][$env] = array($arch);
							} elseif(isset($this->packages_index_arr[$packageName][$env])) {
								$this->packages_index_arr[$packageName][$env][] = $arch;
							} else {
								$this->packages_index_arr[$packageName][$env] = array($arch);
							}
							
						}
						
					}
				
				
				
			}
		}
		
		if($MissingIndex === false && $IndexesFromNet === true && $MissingNetIndex === false) {
			$this->PackagesIndexLoadedAll = true;
			// $this->unrep_packages_index_list(); 
		}
		
	}
	
	
	public function unrep_packages_index_list() {
				
		// Show non-rep results in envs
		$EnvResultsByPackage = array();
		echo 'List packages from TrueSet by Environment Results:', "\n";
		echo '#' , count($this->packages_index_arr) , ' packages' , "\n";
			
		foreach($this->packages_index_arr as $package_name => $package_arr) {
			$package_envs_archs = '';
			foreach($package_arr as $package_env => $package_archs) {
				$package_envs_archs .= $package_env.'/['.implode('|',$package_archs).']';
			}
			$EnvResultsByPackage[$package_name] = $package_envs_archs;
		}

		asort($EnvResultsByPackage);
		print_r($EnvResultsByPackage);
		echo "\n" , '-----------------------' , "\n\n";
		exit;
	}
    
    private function showPregError($content, $RegexType, $Regex)
    {
        $preg_error_num = preg_last_error();
        $page_error_msg = isset($this->preg_error_values[$preg_error_num]) ? $this->preg_error_values[$preg_error_num] : '';
        echo '#PregError#=>' , $preg_error_num , $page_error_msg , ' content: ' , $content , 'RegexType: ' , $RegexType , ' Regex: ' , $Regex , "\n";
    }
    
	public static function is_file_data_or_control($path) {
		if ($path  === 'control.tar'    || $path === 'data.tar'
		|| $path === 'control.tar.xz' || $path === 'data.tar.xz'
		|| $path === 'control.tar.gz' || $path === 'data.tar.gz') {
			return true;
		}
		return false;
	}
						
    public static function returnMatchedType($matches)
    {
        foreach ($matches as $match_key => $match_val) {
            if (!is_int($match_key) && !empty($match_val)) {
                return $match_key;
            }
        }
        return false;
    }
    
        
    private function addRule($issuetype, $issuetag, $line, $linematch)
    {
		$this->current_rules_count++;
        if ($this->Debug) {
            echo '#AddRule: ' , $issuetype , ' ' , $issuetag, ' ', $line , ' ' , $linematch , "\n";
        }
        
        if ($this->RawResults) {
			$Res = array(
                'line'=>($this->linenumber === 0 ? 'N/A' : $this->linenumber) ,
                'type' => $issuetype ,
                'linestring' => $line ,
                'linematch' => $linematch ,
                'filename' => $this->current_file_name
            );
			
			// TODO not check with !isset($this->NotesResult[$issuetag])
			if(!in_array($this->result[$this->package_name] , $Res) && !isset($this->NotesResult[$issuetag])) {
				$this->result[$this->package_name][]=$Res;
			}
        }
        
        if (is_array($this->NotesResult)) {
			
			//effected by skip_results/issues?!
			if($this->skip_type_when_found === false && $this->current_file_name != $this->current_file_name_unavailable) {
				if(!isset($this->files[$this->current_file_name])) {
					$this->files[$this->current_file_name] = array();
					$this->files[$this->current_file_name]['rulescount'] = 0; // rules count
					$this->files[$this->current_file_name]['addlinescount'] = 0; //TODO: ?N/A?
				} else {
					$this->files[$this->current_file_name]['rulescount']++;
				}
			}
			
            if (!isset($this->NotesResult[$issuetag])) {
                if ($this->Debug) {
                    echo '#AddRuleNotesResult: ' , $issuetag , ' = ', $this->package_name , '[' , $this->package_target_arch , "]\n";
                }
                
                $this->NotesResult[$issuetag] = array();
				$this->NotesResult[$issuetag]['name'] = $this->package_name;
				$this->NotesResult[$issuetag]['arch'] = $this->package_target_arch;
				$this->NotesResult[$issuetag]['count'] = 1;

            } else { // count for issue-type detections
				$this->NotesResult[$issuetag]['count']++;
			}
        } elseif ($this->Debug) {
            echo '###NotesResultsDisabled###', "\n";
        }
    }
    
    public function scan($filepath)
    {
        $this->GlobalSearch = array();
        $this->timestart=microtime(true);
        $this->cputimestart=getrusage();
        $this->NotesResult = is_array($this->NotesResult) ? array() : false;
        $this->result = array();
		
		//
        // Go Over File //
        //

        $this->pattern_global_internal     = $this->rules;
        $rules_constant_internal           = $this->rules_constant;
        $pattern_filenames_global_internal = $this->rules_filenames;
        foreach ($this->issues_both_values as $ibv_key => $ibv_value) {
            $this->issues_both_values[$ibv_key]['1f'] = 0;
            $this->issues_both_values[$ibv_key]['2f'] = 0;
        }

        $file = basename($filepath);
        $this->package_name = substr($file, 0, strpos($file, '_'));
        if (empty($this->package_name)) {
            $this->package_name = 'invalid_package_name_'.$this->invalidcounter;
			$this->invalidcounter++;
        }
        $this->current_file_name = $this->current_file_name_unavailable; 
		$this->files = array();
		
		$this->filesize = filesize($filepath);
		
		$this->FileResults[$filepath] = array('package_name' => $this->package_name, 'arch' => 'amd64');
		$this->current_rules_count = 0;
		$this->global_search_rules_count = 0;
        $this->linecount = 0;
		
		if($this->PackagesIndexLoadedAll && !empty($this->packages_index_arr) && !isset($this->packages_index_arr[$this->package_name])) {
			echo 'Removing file: ' , $filepath , ' Reason: not exists in packages list (probably RMed)' , "\n";
			unlink($filepath);
			return;
		}
		
        $filecontent = file_get_contents($filepath);
        if ($filecontent === false) {
            echo 'Error open file: '.$filepath;
			return;
        }
		
        $this->filelines = explode("\n", $filecontent);
        if (empty($this->filelines)) {
            echo 'Error Split the file by new-lines: ' . $filepath . "\n";
            return;
        }
		
	
		// detect ARCH from file first line aka FROM: _amd64.buildinfo
		if($this->endsWith($this->filelines[0], '.buildinfo')) {
			$arch_str_start = strrpos ($this->filelines[0], '_');
			$this->package_target_arch = substr(substr($this->filelines[0] , strrpos ($this->filelines[0], '_')), 1, -10);
			
			if($this->BypassTestingEnv && $this->package_target_arch === 'testing') {
				echo 'Removing file: ' , $filepath , ' Reason: from testing env' , "\n";
				unlink($filepath);
				return;
			} /* else { // TODO: remove me = scan non testing.
				return;
			} */
		}
		/*
		TODO:
		
		ADD RM Section:
			Detect by: if all section files has issues as adding lines per section = found everything possible
			
		Add capture_path detection when _flags detected
			
		*/
		
		$this->FileResults[$file]['arch'] = $this->package_target_arch;
		

		if ($this->RawResults)
			$this->result[$this->package_name]=array();
    
        // Search Rules only

        foreach ($this->rules_content_search as $rule_content_name => $rule_content) {
            if (isset($rule_content['search'])) {
                $pos = stripos($filecontent, $rule_content['search']);
                if ($pos !== false) {
					$posline = substr($filecontent , $pos-30 , 100);
                    $this->addRule($rule_content_name, $rule_content['tag'], $posline, $rule_content['search']);
                }
            } elseif (isset($rule_content['regex'])) {
                $preg_res = preg_match_all($rule_content['regex'].'iDS', $filecontent, $matches);
                if ($preg_res === 1) {
					foreach($matches[1] as $matched) {
						$this->addRule($rule_content_name, $rule_content['tag'], $matched, $rule_content['regex']);
					}
                } elseif ($preg_res === false) {
                    $this->showPregError('#AllFileContentRuleSearch#', $rule_content_name, $rule_content['regex']);
                }
            }
        }
    
		foreach ($this->GlobalSearchRules as $search_content_name => $search_content) {
            if (isset($search_content['search'])) {
                $pos = stripos($filecontent, $search_content['search']);
                if ($pos !== false) {
					$this->GlobalSearch[$search_content_name]=1;
                }
            } elseif (isset($search_content['regex'])) {
                $preg_res = preg_match_all($search_content['regex'].'iDS', $filecontent, $matches);
                if ($preg_res === 1) {
					$this->GlobalSearch[$search_content_name]=1;
                } elseif ($preg_res === false) {
                    $this->showPregError('#AllFileContentGlobalSearch#', $search_content_name, $search_content['regex']);
                }
            }
        }
		
		unset($filecontent);
		
		$this->global_search_rules_count = $this->current_rules_count;
		
		
        $this->linecount = count($this->filelines);
// Variables to Global Rules //

        $this->in_order_check = false;
        $this->left_order=array();
        $this->right_order=array();
        $this->java_doc_found = false;
// Strip 'empty' lines
        $countlines = $this->linecount - 3;
        $combine_line_last = 0;
        $this->add_array_count = 0;
        $this->rem_array_count = 0;

        // Don't run on lines before known sha256
        for ($i=5; $i<$countlines; $i++) {
// Strip data from line
            $line = $this->stripLine($this->filelines[$i], true, true);
            if ($this->line_min_or_add === '+') {
                $this->add_array_count++;
				if(isset($this->files[$this->current_file_name]['addlinescount'])) {
					$this->files[$this->current_file_name]['addlinescount']++;
				}
            } elseif ($this->line_min_or_add === '-') {
                $this->rem_array_count++;
            }
                                
            $preg_res = preg_match('/^@@ -\d+,\d+ \+\d+,\d+ @@$/DS', $line);
            if ($preg_res === 1 || $line === '?' || empty($line)) {
                if ($this->Debug) {
                    echo '##REMLINE1## ' , $line , "\n";
                }
                  continue;
            } elseif ($preg_res === false) {
                $this->showPregError($line, 'LinesDiff', '/^@@ -\d+,\d+ \+\d+,\d+ @@$/DS');
            }

            if (mb_substr($line, 0, 3) === '¦? ') {
                if ($this->Debug) {
                    echo '##REMLINE2## ' , $line , "\n";
                }
            
                if (strpos($line, 'No file format specific differences found inside, yet data differs') !== false) {
                    $archive_timestamp = ($this->endsWith($this->current_file_name, '.zip') ? 'timestamps_in_zip'
                                         : ($this->endsWith($this->current_file_name, '.jar') ? 'timestamps_in_jar' : false));
                    if ($archive_timestamp !== false && !isset($this->skip_type_when_found_arr[$archive_timestamp])) {
                        $this->addRule('archivetimestamp', $archive_timestamp, $line, 'No file format specific differences found inside, yet data differs');
                        $this->skip_type_when_found_arr[$archive_timestamp]=true;
                    }
                
                    //&& ($this->skip_type_when_found_arr) && !array_key_exists($archive_timestamp ,$this->NotesResult ))
                } elseif (strpos($line, 'Files similar despite different names') !== false
                            && !isset($this->skip_type_when_found_arr['??random-file-name??'])) {
                    $this->addRule('random-file-name', '??random-file-name??', $line, 'Files similar despite different names');
                    $this->skip_type_when_found_arr['??random-file-name??']=true;
                } elseif (strpos($line, 'symlink') !== false && !isset($this->skip_type_when_found_arr['??symlink??'])) { 
                    $this->addRule('symlink', '??symlink??', $line, 'symlink');
                    $this->skip_type_when_found_arr['??symlink??']=true;
				} elseif (strpos($line, 'ordering differences only') !== false) {
					// TODO: change to other issues when applied [currently DiffoScope has this only in JSON parsing]
                    $this->addRule('json-order', '??ordering_differences_json??', $line, 'ordering_differences_json');
                    $this->skip_type_when_found_arr['??ordering_differences_json??']=true;
				}
								
                continue;
            }
        
              $this->line = $line;
            if ($this->Debug) {
                echo $line , "\n";
            }

              $this->linenumber = $i+1;
// Catch File Name //
    
              $preg_res = preg_match('/^+-- (.+)$/DS', $line, $filename_matches);
            if ($preg_res === 1) {
				$current_filename = $filename_matches[1];
				
                if ($this->Debug) {
                    echo 'FileName Line Match: ' , $current_filename , "\n";
                }
        
                // Finish/Do Order Check
                if ($this->in_order_check) {
                    $this->doOrderCheck();
                }
        
                if (!in_array($current_filename, $this->skip_file_names)) {
                    $this->current_file_name=$current_filename;
										
					 // rules count
					if(isset($this->filelines[$this->linenumber])) {
						$templine = $this->stripLine($this->filelines[$this->linenumber], false, false);
						if(preg_match('/^+-- (.+)$/DS', $templine) === 0) {
							$this->files[$current_filename] = array();
							$this->files[$current_filename]['rulescount'] = 0;
							$this->files[$current_filename]['addlinescount'] = 0;
						}
					} else {
						$this->files[$current_filename] = array();
						$this->files[$current_filename]['rulescount'] = 0;
						$this->files[$current_filename]['addlinescount'] = 0;
					}
					
                    if ($this->Debug) {
                        echo 'Filename: ', $this->current_file_name , "\n";
                    }
            

                    if (in_array(pathinfo($this->current_file_name, PATHINFO_EXTENSION), $this->ext_for_order) ||
						in_array($this->current_file_name, $this->filenames_for_order)) {
                        $this->in_order_check=true;
                    }
            
                    // ADD FileName RULES //
            
                    if (!empty($pattern_filenames_global_internal)) {
    // found all possible issues
                
                        $issue_found = false;
                        foreach ($pattern_filenames_global_internal as $pattern_filename_key => $pattern_filename) {
                        // type: 0=regex , 1=startwith, 2=endwith, 3=startwith+endwith, 4=search
                            switch ($pattern_filename['type']) {
                                case 0:
									$pattern_check = '/'.$pattern_filename['regex'].'/iDS';
                                    $preg_res = preg_match($pattern_check, $this->current_file_name, $filename_issues_matches);
                                    if ($preg_res === 1) {
                                        if ($this->Debug === 2) {
                                            echo 'PatternFileNameCheck: ' , $pattern_check , "\n";
                                            print_r($filename_issues_matches);
                                        }
                                    
                                        $issue_type = $pattern_filename_key;
                                        $issue_tag  = $pattern_filename['tag'];
                                        if ($issue_type === 'javadoc') {
                                            $this->java_doc_found = true;
                                        } else {
                                            $this->addRule($issue_type, $issue_tag, $this->current_file_name, $filename_issues_matches[1]);
                                            $issue_found=true;
                                        }
                                    } elseif ($preg_res === false) {
                                        $this->showPregError($this->current_file_name, 'pattern_filename', $pattern_check);
                                    }


                            
                                    break;
                                case 1:
                                    if ($this->startsWith($this->current_file_name, $pattern_filename['startwith'])) {
                                        $this->addRule($pattern_filename_key, $pattern_filename['tag'], $this->current_file_name, 'SW:' . $pattern_filename['startwith']);
                                        $issue_found=true;
                                    }


                                    break;
                                case 2:
                                    if ($this->endsWith($this->current_file_name, $pattern_filename['endwith'])) {
                                        $this->addRule($pattern_filename_key, $pattern_filename['tag'], $this->current_file_name, 'EW:' . pathinfo($this->current_file_name, PATHINFO_EXTENSION));
                                        $issue_found=true;
                                    }


                                    break;
                                case 3:
                                    if ($this->startsWith($this->current_file_name, $pattern_filename['startwith']) && $this->endsWith($this->current_file_name, $pattern_filename['endwith'])) {
                                        $this->addRule($pattern_filename_key, $pattern_filename['tag'], $this->current_file_name, 'SEW:' . pathinfo($this->current_file_name, PATHINFO_EXTENSION));
                                        $issue_found=true;
                                    }


                                    break;
                                case 4:
                                    $pos=stripos($this->current_file_name, $pattern_filename['search']);
									if ($pos !== false) {
                                        $this->addRule($pattern_filename_key, $pattern_filename['tag'], $this->current_file_name, $pattern_filename['search']);
                                        $issue_found=true;
                                    }


                                    break;
                            }
                    
                            if ($issue_found && $this->skip_type_when_found) {
                                unset($pattern_filenames_global_internal[$pattern_filename_key]);
                                $issue_found = false;
                            }
                        }
                    }
                } elseif (in_array($filename_matches[1], $this->start_order_check)) {
                    if ($this->Debug) {
                        echo '$filename_matches[1] === ' , $filename_matches[1] , "\n";
                    }
        
                        $this->in_order_check = true;
                } elseif ($filename_matches[1] === 'encoding' && !isset($this->skip_type_when_found_arr['encoding'])) {
                    $this->addRule('encoding_section', 'different_encoding', $line, $filename_matches[1]);
                    $this->skip_type_when_found_arr['encoding']=true;
                } elseif ($filename_matches[1] === 'showttf {}' && !isset($this->skip_type_when_found_arr['fontforge_resets_modification_time'])) {
                    $this->addRule('showttf_section', 'fontforge_resets_modification_time', $line, $filename_matches[1]);
                    $this->skip_type_when_found_arr['fontforge_resets_modification_time']=true;
                }
            } // Changed Lines + or -
            elseif (in_array($this->line_min_or_add, array('+', '-'))) {
				
                if ($this->in_order_check) {
                    if ($this->Debug === 2) {
                        echo '**inORDER** add line #' , count($this->left_order) , ' V: ' , $line, "\n";
                    }
                        
                    if ($this->line_min_or_add === '-') {
                        array_push($this->left_order, substr($line, 1));
                    } else {
                        array_push($this->right_order, substr($line, 1));
                    }
                }
                    
                $linestrlen = strlen($line);
                if ($i > $combine_line_last && ($linestrlen > 15 && $linestrlen < 20)) {
                    if ($this->Debug) {
                        echo 'IN COMBINE!1!' , "\n";
                    }
                        
                    $z=$i+1;
                    $combine_start_line=$z;
                    $temp_combine_line = $line;
                    $combine_symbol = $this->line_min_or_add;
                    while ($z < $countlines) {
                        $temp_line = $this->stripLine($this->filelines[$z], false, true);
                        if ($this->line_min_or_add === $combine_symbol) {
                            $z=$z+1;
                            $temp_combine_line .= $temp_line;
                        } else {
                            $combine_line_last = $z;
                            $this->CheckGlobalRules($temp_combine_line, true);
                            break;
                        }
                    }
                    $this->line_min_or_add = $combine_symbol;
                }
                    
                // Global Result
                $this->CheckGlobalRules($line, false);
				
				#### START SMALL TEST ###
					
				// Test Small diffs for one-liners
				if($this->line_min_or_add === '-' ) {

					$lineS = $this->stripLine($this->filelines[$i-1], false, true);
					$lineS_min_or_add = $this->line_min_or_add;
					$line2 = $this->stripLine($this->filelines[$i+1], false, true);
					$line2_min_or_add = $this->line_min_or_add;
					$lineE = $this->stripLine($this->filelines[$i+2], false, true);
					$lineE_min_or_add = $this->line_min_or_add;

					$this->line_min_or_add = '-';
					
					if ($line2_min_or_add === '+' && !in_array($lineS_min_or_add, array('+', '-')) && !in_array($lineE_min_or_add, array('+', '-'))) {
					
						$line1_count = strlen($line);
						$line2_count = strlen($line2);
						$line1_arr = str_split($line);
						$line2_arr = str_split($line2);
						
						if($line1_count === $line2_count) {
							$line_arr_diff = array_diff($line2_arr,$line1_arr);
							if(!empty($line_arr_diff) && $this->endsWith($this->current_file_name, '.1') // TODO1 regex .\d+
								&& !isset($this->NotesResult['build_id_variation_requiring_further_investigation'])
								&& !isset($this->NotesResult['timestamps_in_manpages_generated_by_help2man'])
								&& !(isset($this->NotesResult['??tsman0??'])
									|| isset($this->NotesResult['??tsman1??'])
									|| isset($this->NotesResult['??tsman2??'])
									|| isset($this->NotesResult['??tsman3??'])
									|| isset($this->NotesResult['??tsman4??'])
									|| isset($this->NotesResult['??tsman5??'])
									|| isset($this->NotesResult['??tsman6??'])
									|| isset($this->NotesResult['??tsman7??'])
									|| isset($this->NotesResult['??tsman8??'])) && $this->package_target_arch === 'i386') {
								$this->addRule('docbook_to_man_one_byte_delta', 'docbook_to_man_one_byte_delta', $line2 , implode(',',$line_arr_diff));
							} else if(empty($this->NotesResult)) {
								$this->addRule('??1line_diff_same_chars??', '??1line_diff_same_chars??', $line.'|'.$line2 , 'same-chars');
							}
						} else { 
							$line_count_diff = $line2_count - $line1_count;
							if ($line_count_diff >= 2 &&
								//TODO: use strpos for -e or  -e (if only one of them relevant)
								((strpos($line2, ' -e') !== false || strpos($line2, '-e ') !== false) &&
										(strpos($line, ' -e') === false || strpos($line, '-e ') === false))
								) { 
								
								$this->addRule('bin_sh_is_bash', 'bin_sh_is_bash', $line2, '-e');
							} else if(empty($this->NotesResult)) {
								$this->addRule('??1line_diff_more_chars??', '??1line_diff_more_chars??', $line.'|'.$line2 , 'diff-lines-count: '.$line_count_diff);
							}
						}
					}
				}

				#### END SMALL TEST ###
				
				
            } else {
            // no filename / no + / no - [constant data , same in both sides]
                

                foreach ($rules_constant_internal as $rule_constant_key => $rule_constant) {
                    if (isset($rule_constant['search'])) {
                        if (stripos($line, $rule_constant['search']) !== false) {
                            $this->addRule($rule_constant_key, $rule_constant['tag'], $line, $rule_constant['search']);
                            if ($this->skip_type_when_found) {
                                unset($rules_constant_internal[$rule_constant_key]);
                            }
                             break;
                        }
                    } elseif (isset($rule_constant['regex'])) {
                        $preg_res = preg_match($rule_constant['regex'].'iDS', $line, $matches);
                        if ($preg_res === 1) {
                            $this->addRule($rule_constant_key, $rule_constant['tag'], $line, $matches[1]);
                            if ($this->skip_type_when_found) {
                                unset($rules_constant_internal[$rule_constant_key]);
                            }
                                    
                             break;
                        } elseif ($preg_res === false) {
                            $this->showPregError($line, $rule_constant_key, $rule_constant['regex']);
                        }
                    }
                }
            }
        }

    
        // Finish/Do Order Check
        if ($this->in_order_check) {
            $this->doOrderCheck();
        }

        $this->Consolidate();
		
		// Remove files with too_much_input_for_diff or missing_diff (in order to update them, might be fixed by new re-test)
		if($this->RemoveFilesWithFixedBugs) {
			$toomuchdiff = isset($this->NotesResult['too_much_input_for_diff']);
			$misingdiff  = isset($this->NotesResult['??missing_diff1??']);
			$wgeterror   = isset($this->NotesResult['??wget_error_500??']);
			if(is_array($this->NotesResult) && ($toomuchdiff || $misingdiff) && ($this->filesize >= 14680064 || $wgeterror) && $this->filesize < 80680064) {
				echo 'Removing file: ' , $filepath , ' Reason: ' , ($toomuchdiff ? 'too_much_input_for_diff' : '') , ($misingdiff ? 'missing_diff' : '') , "\n";
				unlink($filepath);
			}
		}
				

		
    }

	
    public function EnvByPackageAndArch($packagenm, $arch_nm)
    {
		if(isset($this->packages_index_arr[$packagenm])) {
			foreach($this->envs as $env) {
				if(isset($this->packages_index_arr[$packagenm][$env]) && in_array($arch_nm , $this->packages_index_arr[$packagenm][$env])) {
					return $env;
				}
			}
		}
		// TODO: change to ??unstable?? maybe or not-found?
		return 'unstable';
	}
	
    // ToDo: add @ or implement size check (strlen($str) >= strlen($strsearch))
    public static function startsWith($str, $strsearch)
    {
        if (is_string($strsearch)) {
            return substr_compare($str, $strsearch, 0, strlen($strsearch)) === 0;
        }
        
        foreach ($strsearch as $s) {
            if (substr_compare($str, $s, 0, strlen($s)) === 0) {
                return true;
            }
        }
        
            return false;
    }
    
    public static function endsWith($str, $strsearch)
    {
        if (is_string($strsearch)) {
            return substr_compare($str, $strsearch, -strlen($strsearch)) === 0;
        }
        
        foreach ($strsearch as $s) {
            if (substr_compare($str, $s, -strlen($s)) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    
    public static function removeTimeStampShort(&$item, $key)
    {
        // 08-Feb-24 11:12
        $item = preg_replace('/\d{2}\-[A-Z][a-z]{2}\-\d{2} \d{2}:\d{2} /', '', $item);
    }
	
    public static function removePrems(&$item, $key)
    {
		// check if catch in : cdebootstrap TODO
		// -drwxr-xr-x
        $item = preg_replace('/[-+][d-](?:[r-][w-][x-]){3}/', '', $item);
    }
    
    
    public static function removeTimeStampLong(&$item, $key)
    {
        // 2015-10-06 03:37:40.000000
        $item = preg_replace('/ \d{4}\-\d{2}\-\d{2} \d{2}:\d{2}:\d{2}\.\d{6} /', '', $item);
    }
    
    public static function removeTimeStampShort2(&$item, $key)
    {
        // Sep  5 2016 [
        $item = preg_replace('/[A-Z][a-z]{2}  \d{1,2} 201[6|7] \[/', '', $item);
    }
    
    
    public static function removeEndChars(&$item, $key)
    {

        if (strlen($item) > 21) {
            $item = substr($item, 0, -17);
        } else {
            $item = $item;
        }
    }
    
    
    public static function getEndChars(&$item, $key)
    {
        
        if (strlen($item) > 21) {
            $item = substr($item, -17);
        } else {
            $item = $item;
        }
    }
    
    
    public static function explodeAndSortByComma(&$item, $key)
    {

        $delimeter = ',';
//TODO, change to value from parameter
        $stipped_item=preg_replace('/[^'.preg_quote($delimeter).'[:alnum:][:space:]]/u', '', $item);
        $part_array = explode(',', $stipped_item);
        if (count($part_array) > 1) {
            sort($part_array);
            $item = implode($delimeter, $part_array);
        } else {
            $item = $item;
        }
    }
    
    private function stripLine($line, $min_or_add_remains, $change_line_min_or_add_global)
    {
        if ($this->Debug === 2) {
            echo '##Line-Before-Strip##' , $line , "\n";
        }
        
        // Strip new-lines at end of string
        $line = rtrim($line, "\r\n");
// Strip start of string
        $line = preg_replace('/^(?:¦ (?:  )?)+/', '', $line);
// Strip Binary/HexDump

		$LineMinOrAdd = substr($line, 0, 1);
		if($change_line_min_or_add_global) {
			$this->line_min_or_add = $LineMinOrAdd;
		}
        $temp_line_count = strlen($line);
        $line = preg_replace('/^[+\- ][0-9a-f]{8}: (?:[0-9a-f]{4} ){8} /', '', $line);
        $line = preg_replace('/^(?:[+\-]  | )0x(?:[0-9a-f]{8} ){5}/', '', $line);
        if ($min_or_add_remains && (strlen($line) !== $temp_line_count)) {
            return $LineMinOrAdd.$line;
        }
        return $line;
    }

    
    
    private function doOrderCheck()
    {
        if ($this->Debug) {
            echo 'In Order Check', "\n";
        }
            
        $this->in_order_check = false;
		
        $leftsize=count($this->left_order);
        $rightsize=count($this->right_order);

        // fix dahdi-linux FNs (aka strip umask)
		array_walk($this->left_order, array('DiffoscopeAnalyze', 'removePrems'));
		array_walk($this->right_order, array('DiffoscopeAnalyze', 'removePrems'));
		
        $sumsize=count(array_unique(array_merge($this->left_order, $this->right_order)));
        if ($this->Debug) {
            echo 'ArrayLeft: ' , $leftsize , ' | ArrayRight: ' , $rightsize , ' SUM: ', $sumsize , "\n";
            print_r($this->left_order);
            print_r($this->right_order);
        }
            
        if ($leftsize === $rightsize) {
            if ($sumsize === $leftsize) {
                if ($this->startsWith($this->current_file_name, './usr/lib/') && $this->endsWith($this->current_file_name, '.a')) {
                    $this->addRule('random_order_a_file_in_usr_lib', 'random_order_in_static_libraries', $this->line, '#ordercheck#');
                } elseif ($this->endsWith($this->current_file_name, '.html')) {
                    if ($this->java_doc_found === true) {
                        $this->addRule('random_order_in_html_global_java-doc_found', 'random_order_in_documentation_generated_by_javadoc', $this->line, '#ordercheck#');
                    } else {
                        $this->addRule('??random_order_in_html??', '??random_order_in_html??', $this->line, '#ordercheck#');
                    }
                } elseif ($this->current_file_name === './md5sums') {
                    $this->addRule('random_order_in_./md5sums', 'random_order_in_md5sums', $this->line, '#ordercheck#');
                } elseif ($this->endsWith($this->current_file_name, array('.tar','.tar.gz','.tar.xz'))) {
                    
					if ($this->is_file_data_or_control($this->current_file_name))
						$this->addRule('varying_ordering_in_data_tar_gz_or_control_tar_gz', 'varying_ordering_in_data_tar_gz_or_control_tar_gz', $this->line, '#ordercheck#');
					else
						$this->addRule('random_order_in_tarball', 'random_order_in_tarball', $this->line, '#ordercheck#');

                } elseif ($this->endsWith($this->current_file_name, '.db')) {
                          //
                    $preg_res = preg_match('/VALUES\(\d+,\d+,\d+,\d+,\d+/', $this->left_order[0]);
                    if ($preg_res === 1) {
                    //TODO: check tagainijisho FNs
                        $this->addRule('qt_translate_noop_nondeterminstic_ordering', 'qt_translate_noop_nondeterminstic_ordering', $this->line, '#ordercheck#');
                    } else {
                        $this->addRule('random_order_in_ibus_table_createdb_output', 'random_order_in_ibus_table_createdb_output', $this->line, '#ordercheck#');
                    }
                            
                    if ($preg_res === false) {
                        $this->showPregError($this->left_order[0], 'db_ordering_noop_detect', '/VALUES\(\d+,\d+,\d+,\d+,\d+/');
                    }
                } elseif ($this->endsWith($this->current_file_name, '.pom')) {
                    $this->addRule('random_ordering_in_pom', 'random_ordering_in_pom', $this->line, '#ordercheck#');
                } elseif ($this->endsWith($this->current_file_name, '.jar')) {
                    $this->addRule('??random_ordering_in_jar??', '??random_ordering_in_jar??', $this->line, '#ordercheck#');
                } elseif ($this->endsWith($this->current_file_name, '.Named')) {
                    if ($this->current_file_name === 'META-INF/sisu/javax.inject.Named') {
                        $this->addRule('random_order_in_sisu_javax_inject_named', 'random_order_in_sisu_javax_inject_named', $this->line, '#ordercheck#');
                    } else {
                        $this->addRule('??random_ordering_Named_file??', '??random_ordering_Named_file??', $this->line, '#ordercheck#');
                    }
                } elseif ($this->endsWith($this->current_file_name, '.hhp')) {
                    //$this->addRule('??random_ordering_hhp_file??', '??random_ordering_hhp_file??', $this->line, '#ordercheck#');
					$this->addRule('sphinx_htmlhelp_readdir_sensitive', 'sphinx_htmlhelp_readdir_sensitive', $this->line, '#ordercheck#');
                } elseif ($this->endsWith($this->current_file_name, '.enums.xml')) {
                    $this->addRule('nondeterminstic_ordering_in_gsettings_glib_enums_xml', 'nondeterminstic_ordering_in_gsettings_glib_enums_xml', $this->line, '#ordercheck#');
                } elseif ($this->current_file_name === './clilibs') {
                    $this->addRule('clilibs_line_order', 'clilibs_line_order', $this->line, '#ordercheck#');
                }  elseif (isset($this->GlobalSearch['getypebase'])) {
                    $this->addRule('valac_permutes_get_type_calls', 'valac_permutes_get_type_calls', $this->line, '#ordercheck#');
                } else {
                    $ext = pathinfo($this->current_file_name, PATHINFO_EXTENSION);
                    $this->addRule('??random_ordering_in_'.$ext.'_file??', '??random_ordering_in_'.$ext.'_file??', $this->line, '#ordercheck#');
                }
            } else {
                $left_temp_test  = $this->left_order;
                $right_temp_test = $this->right_order;
                if (array_walk($left_temp_test, array('DiffoscopeAnalyze', 'removeTimeStampShort'))
                    && array_walk($right_temp_test, array('DiffoscopeAnalyze', 'removeTimeStampShort'))) {
                    if ($leftsize === count(array_unique(array_merge($left_temp_test, $right_temp_test)))) {
                        if ($this->endsWith($this->current_file_name, '.jar')) {
                            $this->addRule('timestamps_in_jar', 'timestamps_in_jar', $this->line, '#order_timestamp_check#');
                        } else {
                            $this->addRule('??timestamps_short_in_??', '??timestamps_short_in_??', $this->line, '#order_timestamp_short_check#');
                        }
                        $this->CleanOrderArrays();
                        return;
                    }
                }
                        
                $left_temp_test  = $this->left_order;
                $right_temp_test = $this->right_order;
                if (array_walk($left_temp_test, array('DiffoscopeAnalyze', 'removeTimeStampLong'))
                    && array_walk($right_temp_test, array('DiffoscopeAnalyze', 'removeTimeStampLong'))) {
                    if ($leftsize === count(array_unique(array_merge($left_temp_test, $right_temp_test)))) {
                        if ($this->is_file_data_or_control($this->current_file_name)) {
                            $this->addRule('varying_mtimes_in_data_tar_gz_or_control_tar_gz', 'varying_mtimes_in_data_tar_gz_or_control_tar_gz', $this->line, '#order_timestamp_long_check#');
                        } elseif ($this->endsWith($this->current_file_name, '.tar')) {
                            $this->addRule('timestamps_in_tarball', 'timestamps_in_tarball', $this->line, '#order_timestamp_long_check#');
                        } elseif ($this->endsWith($this->current_file_name, '.jar')) {
                            $this->addRule('timestamps_in_jar', 'timestamps_in_jar', $this->line, '#order_timestamp_short_check#');
                        } else {
                            $this->addRule('??timestamps_long_in_??', '??timestamps_long_in_??', $this->line, '#order_timestamp_long_check#');
                        }
                                
                        $this->CleanOrderArrays();
                        return;
                    }
                }
                
                $left_temp_test  = $this->left_order;
                $right_temp_test = $this->right_order;
                if (array_walk($left_temp_test, array('DiffoscopeAnalyze', 'removeTimeStampShort2'))
                    && array_walk($right_temp_test, array('DiffoscopeAnalyze', 'removeTimeStampShort2'))) {
                    if ($leftsize === count(array_unique(array_merge($left_temp_test, $right_temp_test)))) {
                        if ($this->endsWith($this->current_file_name, '.iso')) {
                            $this->addRule('??timestamps_in_iso??', '??timestamps_in_iso??', $this->line, '#order_timestamp_short2_check#');
                        } else {
                            $this->addRule('??timestamps_short2_in_??', '??timestamps_short2_in_??', $this->line, '#order_timestamp_short2_check#');
                        }
                        $this->CleanOrderArrays();
                        return;
                    }
                }
                        
                $left_temp_test  = $this->left_order;
                $right_temp_test = $this->right_order;
                if (array_walk($left_temp_test, array('DiffoscopeAnalyze', 'removeEndChars'))
                    && array_walk($right_temp_test, array('DiffoscopeAnalyze', 'removeEndChars'))) {
                    if ($leftsize === count(array_unique(array_merge($left_temp_test, $right_temp_test)))) {
                        $ordering_ext_issue = true;
                    //TODO: detect what removed, does it \w or \d if it's \d probably timestamp issue.
                         $left_temp_test  = $this->left_order;
                        $right_temp_test = $this->right_order;
                        if (array_walk($left_temp_test, array('DiffoscopeAnalyze', 'getEndChars'))
                            && array_walk($right_temp_test, array('DiffoscopeAnalyze', 'getEndChars'))) {
                            $combine_temp_test=array_unique(array_merge($left_temp_test, $right_temp_test));
                            $ordering_ext_issue = false;
                            foreach ($combine_temp_test as $endvalue) {
                                if (preg_match('/^[\.\d]+\D{1,5}$/DS', $endvalue) === false) {
                                    $ordering_ext_issue = true;
                                }
                            }
                        }

                         $ext = pathinfo($this->current_file_name, PATHINFO_EXTENSION);
                        if (empty($ext)) {
                            $ext='[No_extension]'.$this->current_file_name;
                        }
                                    
                        if ($ordering_ext_issue) {
                            $this->addRule('??random_order_in_file_random_tmp_names??', '??random_ordering_in_'.$ext.'_file_and_random_tmp_names??', $this->line, '#ordercheck-tmp-name#');
                        } else {
                            $this->addRule('??random_order_in_file_NUMERIC_Suffix??', '??random_ordering_in_'.$ext.'_NUMERIC_file_and_random_tmp_names??', $this->line, '#ordercheck-numeric-suffix#');
                        }
                                    
                        $this->CleanOrderArrays();
                        return;
                    }
                }
                        
                // in-line order detect
                        
                $left_temp_test  = $this->left_order;
                $right_temp_test = $this->right_order;
                if (array_walk($left_temp_test, array('DiffoscopeAnalyze', 'explodeAndSortByComma'))
                    && array_walk($right_temp_test, array('DiffoscopeAnalyze', 'explodeAndSortByComma'))) {
                    if ($leftsize === count(array_unique(array_merge($left_temp_test, $right_temp_test)))) {
						if(!isset($this->GlobalSearch['haskell']) && !isset($this->GlobalSearch['python'])) { // TODO: add look for 	Recommends: at string? or Depends:
							$this->addRule('??random_order_in_line_comma??', '??random_order_in_line_comma??', $this->line, '#order_from_lines_comma_check#');
						} elseif(isset($this->GlobalSearch['haskell'])) {
							$this->addRule('random_order_in_dh_haskell_substvars', 'random_order_in_dh_haskell_substvars', $this->line, '#order_from_lines_comma_check#');
						} elseif(isset($this->GlobalSearch['python'])) {
							$this->addRule('random_order_in_dh_pythonX_substvars', 'random_order_in_dh_pythonX_substvars', $this->line, '#order_from_lines_comma_check#');
						}
                        $this->CleanOrderArrays();
                        return;
                    }
                }
            }
        }
        $this->CleanOrderArrays();
    }
    
    private function cleanOrderArrays()
    {
        $this->left_order  = array();
        $this->right_order = array();
    }
    
    private function checkGlobalRules($line, $combined)
    {
        if (count($this->pattern_global_internal) == 0) {
// found all issues
            return;
        }
        
        // Strip + or -
            $line = substr($line, 1);
/*
		if ($this->Debug === 2) {
			echo 'checkGlobalRules>Line: ' , $line , "\n";
			var_dump($combined);
		}
		*/
		$found_check_issues = array();
        foreach ($this->pattern_global_internal as $pattern_key => $pattern) {
			
			// subsearch
			if(isset($pattern['subsearch'])) {
				if(in_array($pattern['subsearch'], $found_check_issues)) {
					continue;
				}
			}
			
            if ($pattern['capture_from_start'] === true) {
                $pattern_check = '/^'.$pattern['global'].'/';
            } else {
                $pattern_check = '/'.$pattern['global'].'/';
            }
			
			if(!isset($pattern['case_sensetive'])) {
				$pattern_check .= 'i';
			}
			
            $pattern_check .= 'DS';
            $preg_res = preg_match($pattern_check, $line, $matches1);
            if ($this->Debug === 2) {
                echo 'PatternCheck: ' , $pattern_check , "\n";
            }
            if ($preg_res === 1) {
				
				array_push($found_check_issues, $pattern_key);
				
                if ($this->Debug === 2) {
                    print_r($matches1);
                }
                $this->ProcessGlobalRules($pattern_key, $matches1);
            } elseif ($preg_res === false) {
                $this->showPregError($line, 'pattern_global_internal', $pattern_check);
            }
        }
                    
        if ($combined === false && ((!isset($this->NotesResult['captures_build_path']) && (strpos($line, '1st') !== false || strpos($line, 'nd/') !== false))
            || (!isset($this->NotesResult['user_hostname_manually_added_requiring_further_investigation']) && strpos($line, 'lder1') !== false))) {
            if ($this->Debug) {
                echo '# Try Combine Some issues [PATH/UserName] detect#' , "\n";
            }
            
            //$current_line_min_or_add = $this->line_min_or_add;
            $templine = $this->stripLine($this->filelines[$this->linenumber-2], false, false);
            //$this->line_min_or_add = $current_line_min_or_add;
            if ($this->Debug) {
                echo '#line1: ' , $this->line_min_or_add , '|' , $templine.$line;
                echo ' templine:', $templine , 'Line:', substr($line, 1), "\n";
            }
                
            $this->CheckGlobalRules($templine.substr($line, 1), true);
        }
    }
    
    private function processGlobalRules($rule_name, $matches)
    {
        $issue_type = $this->returnMatchedType($matches);
        $issue_tag  = $this->rules[$rule_name]['tag'];
        if ($this->Debug) {
            echo '#ProcessGlobalRules: ', $issue_type , ' tag: ' , $issue_tag , "\n";
        }
                
        if (isset($this->issues_both_values[$issue_type])) {
            if ($matches[$issue_type]     === $this->issues_both_values[$issue_type][1]) {
                $this->issues_both_values[$issue_type]['1f'] = 1;
            } elseif ($matches[$issue_type] === $this->issues_both_values[$issue_type][2]) {
                $this->issues_both_values[$issue_type]['2f'] = 1;
            }
            if ($this->issues_both_values[$issue_type]['1f'] === 1 && $this->issues_both_values[$issue_type]['2f'] === 1) {
                if ($this->Debug) {
                    echo 'AddRule: ', $issue_type , ' 1f & 2f === 1 ' , "\n";
                }
                        
                $this->addRule($issue_type, $issue_tag, $this->line, $matches[1]);
                if ($this->skip_type_when_found) {
                    unset($this->pattern_global_internal[$rule_name]);
                }
            }
        } else {
            if ($issue_tag === 'captures_build_path'
                && !in_array(pathinfo($this->current_file_name, PATHINFO_EXTENSION), $this->ext_for_manual_path)
                && preg_match('/(\.so(?:[\.\d]+))$/', $this->current_file_name) !== 1) {
            // ToDo how-to-show this info?
			// captures_build_path_manual
                $this->addRule($issue_type, '??'.$issue_tag.'_manual??', $this->line, $matches[1]);
            }
                    
            $this->addRule($issue_type, $issue_tag, $this->line, $matches[1]);
            if ($this->skip_type_when_found && !in_array($rule_name, $this->rulesNotSkip)) {
                unset($this->pattern_global_internal[$rule_name]);
            }
        }
    }
    
    
    private function consolidate()
    {
		/*
        if ($this->RawResults) {
			//TODO - implement //
        }
		*/
		if (is_array($this->NotesResult)) {
			$NotesResultTempRestore = array();
			// TEMP ISSUE to ADD Later [aka not count in count issues] //
			if(isset($this->NotesResult['??1line_diff_same_chars??'])) {
				$NotesResultTempRestore['??1line_diff_same_chars??'] = $this->NotesResult['??1line_diff_same_chars??'];
				unset($this->NotesResult['??1line_diff_same_chars??']);
			}
				
			
			if(isset($this->NotesResult['different_encoding_in_html_by_docbook_xsl']) && !isset($this->NotesResult['different_encoding'])) {
				unset($this->NotesResult['different_encoding_in_html_by_docbook_xsl']);
			}
			
			if($this->java_doc_found === false && isset($this->NotesResult['locale_in_documentation_generated_by_javadoc'])) {
				unset($this->NotesResult['locale_in_documentation_generated_by_javadoc']);
			}
			
			if(isset($this->NotesResult['timestamps_in_documentation_generated_by_javadoc']) && 
				(!isset($this->NotesResult['??date-issue-tag1??']) || !isset($this->NotesResult['??GMT TZ??']))) {
				unset($this->NotesResult['timestamps_in_documentation_generated_by_javadoc']);
			}
						
			if((isset($this->NotesResult['??diff_in_pe_binaries??']) || isset($this->NotesResult['timestamps_in_pe_binaries'])) && !isset($this->NotesResult['??pe_file??'])) {
				unset($this->NotesResult['??diff_in_pe_binaries??']);
				unset($this->NotesResult['timestamps_in_pe_binaries']);
			}
			/*
			if(isset($this->NotesResult['records_build_flags'])) {
				if(!isset($this->NotesResult['??build_flags_capture_search??'])) {
					$this->NotesResult['records_build_flags_other'] = $this->NotesResult['records_build_flags'];
					unset($this->NotesResult['records_build_flags']);
				}
				unset($this->NotesResult['??build_flags_capture_search??']);
			}
			*/

			if(isset($this->NotesResult['golang_compiler_captures_build_path_in_binary'])) {
				if(!isset($this->NotesResult['??golanghex??'])) {
					$this->NotesResult['golang_compiler_captures_build_path_in_binary_other'] = $this->NotesResult['golang_compiler_captures_build_path_in_binary'];
					unset($this->NotesResult['golang_compiler_captures_build_path_in_binary']);
				}
				unset($this->NotesResult['??golanghex??']);
			}
			
			if(isset($this->NotesResult['build_id_variation_requiring_further_investigation'])) {
				if(isset($this->NotesResult['??buildids??'])) {
					unset($this->NotesResult['??buildids??']);
				}
				$NotesResultCount = count($this->NotesResult);
				if($NotesResultCount === 1) {
					if ($this->NotesResult['build_id_variation_requiring_further_investigation']['count']+6 >= $this->add_array_count) {
						$this->NotesResult['build_id_differences_only'] = $this->NotesResult['build_id_variation_requiring_further_investigation'];
					} else {
						$this->NotesResult['build_id_differences_only_fp_probably'] = $this->NotesResult['build_id_variation_requiring_further_investigation'];
					}
					unset($this->NotesResult['build_id_variation_requiring_further_investigation']);
				} elseif($NotesResultCount === 2 && isset($this->NotesResult['??gnu_debuglink??'])) {
					// TODO: add add_array_count max ^^
					$this->NotesResult['build_id_differences_only'] = $this->NotesResult['build_id_variation_requiring_further_investigation'];
				} else {
						// Too many issues found...
						//$this->NotesResult['build_id_differences_not_only'] = $this->NotesResult['build_id_variation_requiring_further_investigation'];
						unset($this->NotesResult['build_id_variation_requiring_further_investigation']);
				}
			}
			
			// don't report if build_id also found
			if(isset($this->NotesResult['build_id_variation_requiring_further_investigation']) || isset($this->NotesResult['build_id_differences_not_only'])) {
				unset($this->NotesResult['??gnu_debuglink??']);
			}
			
            if (isset($this->NotesResult['randomness_in_html_generated_by_texi2html']) && isset($this->NotesResult['??gecos_second_user??'])) {
                if ($this->Debug) {
                    echo '#Consolidate-Rem: randomness_in_html_generated_by_texi2html & ??gecos_second_user?? to texi2html_captures_users_gecos' , "\n";
                }
                
                $this->NotesResult['texi2html_captures_users_gecos'] = $this->NotesResult['randomness_in_html_generated_by_texi2html'];
                unset($this->NotesResult['randomness_in_html_generated_by_texi2html']);
                unset($this->NotesResult['??gecos_second_user??']);
            }
            
			if(isset($this->NotesResult['captures_users_gecos']) && isset($this->NotesResult['??gecos_second_user??'])) {
				unset($this->NotesResult['??gecos_second_user??']);
			}
                            // Remove path ??subissues?? [that don't have tag] if gen path found//
            if (isset($this->NotesResult['captures_build_path'])) {
				
				// TODO: check for timestamps also + random order issue
				// random_order_in_documentation_generated_by_naturaldocs
				if(isset($this->NotesResult['timestamps_in_documentation_generated_by_naturaldocs'])) {
					$this->NotesResult['build_path_in_documentation_generated_by_naturaldocs'] = $this->NotesResult['timestamps_in_documentation_generated_by_naturaldocs'];
				}
				
                if (isset($this->NotesResult['??captures_build_path_binary??'])) {
                    if ($this->Debug) {
                        echo '#Consolidate-Rem: ??captures_build_path_binary?? when captures_build_path exists' , "\n";
                    }
                    
                    unset($this->NotesResult['??captures_build_path_binary??']);
                }
                
                
                if (isset($this->NotesResult['??captures_build_path_dots??'])) {
                    if ($this->Debug) {
                        echo '#Consolidate-Rem: ??captures_build_path_dots?? when captures_build_path exists' , "\n";
                    }
                    
                    unset($this->NotesResult['??captures_build_path_dots??']);
                }
            }
            
            
            // avoid FP in cpl-plugin-visir
			
		    if (isset($this->NotesResult['copyright_year_in_documentation_generated_by_sphinx']) && isset($this->NotesResult['??last_updated_on??'])) {
                $this->NotesResult['timestamps_in_documentation_generated_by_sphinx'] = $this->NotesResult['copyright_year_in_documentation_generated_by_sphinx'];
            }
			
            if (isset($this->NotesResult['copyright_year_in_documentation_generated_by_sphinx']) && !isset($this->NotesResult['??copyright1??'])) {
                unset($this->NotesResult['copyright_year_in_documentation_generated_by_sphinx']);
            }
			
            if (isset($this->NotesResult['texinfo_mdate_sh_varies_by_timezone']) && !isset($this->NotesResult['??unknownby??'])) {
                unset($this->NotesResult['texinfo_mdate_sh_varies_by_timezone']);
            }			
       
            
            // avoid FP in grinder
            // TODO: adjust ??TH rules (check with relevant only)
            if (isset($this->NotesResult['timestamps_in_manpages_generated_by_help2man']) && !(isset($this->NotesResult['??tsman0??'])
                || isset($this->NotesResult['??tsman1??'])
                || isset($this->NotesResult['??tsman2??'])
                || isset($this->NotesResult['??tsman3??'])
                || isset($this->NotesResult['??tsman4??'])
                || isset($this->NotesResult['??tsman5??'])
                || isset($this->NotesResult['??tsman6??'])
                || isset($this->NotesResult['??tsman7??'])
                || isset($this->NotesResult['??tsman8??']))) {
                unset($this->NotesResult['timestamps_in_manpages_generated_by_help2man']);
            }
            
            if (isset($this->NotesResult['golang_compiler_captures_build_path_in_binary']) && !isset($this->NotesResult['captures_build_path'])) {
                if ($this->Debug) {
                    echo '#Consolidate-Rem: golang_compiler_captures_build_path_in_binary' , "\n";
                }
                unset($this->NotesResult['golang_compiler_captures_build_path_in_binary']);
            }
            
            if (isset($this->NotesResult['user_in_documentation_generated_by_gsdoc'])
                && !isset($this->NotesResult['user_hostname_manually_added_requiring_further_investigation'])) {
                if ($this->Debug) {
                    echo '#Consolidate-Rem: user_in_documentation_generated_by_gsdoc' , "\n";
                }
                unset($this->NotesResult['user_in_documentation_generated_by_gsdoc']);
            }
            
            foreach ($this->subissues as $gen => $sub) {
                foreach ($sub as $subitem) {
                    if (isset($this->NotesResult[$subitem])) {
                        if ($this->Debug) {
                            echo '#Consolidate-Rem-FSubIssue: ', $gen , "\n";
                        }
                        unset($this->NotesResult[$gen]);
                    }
                }
            }
			
			// Restore issues:
			if(isset($NotesResultTempRestore['??1line_diff_same_chars??'])) {
				$this->NotesResult['??1line_diff_same_chars??'] = $NotesResultTempRestore['??1line_diff_same_chars??'];
			}
			
        }
    }
}


$DiffoscopeAnalyzer = new DiffoscopeAnalyze();
// Comment out to run in debug mode on files in $scan Array
// die(test());

function test()
{
    global $DiffoscopeAnalyzer;
    $DiffoscopeAnalyzer->Debug = 2;
// 2 more verbose , 1/true less verbose
    $DiffoscopeAnalyzer->RawResults = true;
// change COPYARRAY to some array in output [FNs results]
    // $scans = COPYARRAY;

    if (empty($scans) || !is_array($scans)) {
        return '$scans variable is empty or not Array';
    }
    
    foreach ($scans as $scan_key => $scan_val) {
        if (!is_numeric($scan_key)) {
        // Show only scans with Missing Issue X
            if (!in_array('different_due_to_umask', $scan_val)) {
                continue;
            } else {
                echo 'check: ' , $scan_key , ' miss:' , implode(' , ', $scan_val) , "\n";
            }
            if (strpos($scan_key, 'arachne') !== false) {
                continue;
            }
            
                $DiffoscopeAnalyzer->scan($scan_key);
        } else {
            $DiffoscopeAnalyzer->scan($scan_val);
        }
        
        echo "\n\n\n" , '===== Results =====' , "\n\n\n";
        if (!is_numeric($scan_key)) {
            echo 'Missing Issues: ' , implode(' , ', $scan_val) , "\n";
        }

        echo "\n ---- result: ---- \n";
        print_r($DiffoscopeAnalyzer->result);
        echo "\n ---- NotesResult: ---- \n";
        print_r($DiffoscopeAnalyzer->NotesResult);
        echo "\n\n\n --------------------------- \n\n\n";
    }

    return 'test() completed!';
}

$TrueSet = false;
if (file_exists($LocalPackagesYaml) && is_readable($LocalPackagesYaml)) {
    $TrueSet = yaml_parse_file($LocalPackagesYaml);
    echo 'Use local ' , $LocalPackagesYaml , ' file' , "\n";
} else {
    $TrueSet = yaml_parse_url($RemotePackagesYaml);
    echo 'Use remote packages.yml file from: ' , $RemotePackagesYaml , "\n";
}

if ($TrueSet === false) {
    $has_trueset = false;
    echo 'Error in parsing packages.yml file' , "\n";
} else {
    $has_trueset = true;
}

$UniqueNoteResults = $has_trueset && true;
$files = glob($dir . DIRECTORY_SEPARATOR .'*.txt.gz');
$files_count = count($files);
echo 'Go over #' , $files_count , "\n";
$file_i=0;
$GlobalRawResults=array();
foreach ($files as $file) {
    $file_size = filesize($file);
// Limit scan to files < MAX size
    if ($file_size >= $file_max_size_scan || $file_size < $file_min_size_scan) {
        continue;
    }
    
    // DEBUG ###
    echo '#' , $file_i, ' File: ' , $file , "\n";
    $filename = basename($file);
    $file_package = substr($filename, 0, strpos($filename, '_'));

    if ($skip_packages_with_comments_bugs && $has_trueset && isset($TrueSet[$file_package])) {
		$skip_package_with_comment_or_bugs = false;
			$has_bug     = isset($TrueSet[$file_package]['bugs']);
			$has_comment = isset($TrueSet[$file_package]['comments']);
			$has_issue   = isset($TrueSet[$file_package]['issues']);
			if ($has_comment || ($has_bug && $has_issue)) {
					$skip_package_with_comment_or_bugs = true;
			}
			
			/*
			foreach ($TrueSet[$file_package]['issues'] as $tmpissue) {
				if (strpos($tmpissue , 'timestamp') !== false) {
					$skip_package_with_comment_or_bugs = true;
				}
			}				
			*/
			
			if ($skip_package_with_comment_or_bugs === true) {
				$file_i++;
				continue;
			}
    }
	
    $DiffoscopeAnalyzer->scan($file);
	
		
	if($DiffoscopeAnalyzer->RawResults && !empty($DiffoscopeAnalyzer->result) && !empty($DiffoscopeAnalyzer->result[$file_package])) {
		echo 'RawResults:' , "\n";
		print_r($DiffoscopeAnalyzer->result);
		$GlobalRawResults = array_merge($GlobalRawResults,$DiffoscopeAnalyzer->result);
	}
	
	if(!empty($DiffoscopeAnalyzer->files)) {
		echo 'files:' , count($DiffoscopeAnalyzer->files) , "\n";
		print_r($DiffoscopeAnalyzer->files);
		// TODO: change - check RM also
		if($DiffoscopeAnalyzer->global_search_rules_count == 0) { // && TS_ISSUES_COUNT <= FOUND_ISSUE_COUNT
			foreach($DiffoscopeAnalyzer->files as $f => $nm) {
				if($nm === 0 && !$DiffoscopeAnalyzer->endsWith($f, '.deb') && !$DiffoscopeAnalyzer->startsWith($f, array('data.tar','control.tar')) && !in_array($f, array('./md5sums','./control'))) {
					echo 'Missing Res in:' , $f , "\n";
				}
			}
		}
	}
	
    if (empty($DiffoscopeAnalyzer->NotesResult)) {
        array_push($FN_Results, $file);
    } else {
        $tag_found = false;
        $TP_TrueSetResults[$file] = array();
        $TP_NS_TrueSetResults[$file] = array();
        $NotesResultCount = count($DiffoscopeAnalyzer->NotesResult);
        foreach ($DiffoscopeAnalyzer->NotesResult as $tag => $package_arr) {
			$package = $package_arr['name'];
            if (substr($tag, 0, 1) !== '?') {
                $tag_found = true;
                if ($UniqueNoteResults && isset($TrueSet[$package])) {
                    if (isset($TrueSet[$package]['issues'])) {
                    // Found TP //
                        if (in_array($tag, $TrueSet[$package]['issues'])) {
                            array_push($TP_TrueSetResults[$file], $tag);
                            continue;
                        }
                        
                        // Consolidate by TrueSet [found issue covered by sub-issue]
                        if (isset($DiffoscopeAnalyzer->subissues[$tag])) {
                            foreach ($DiffoscopeAnalyzer->subissues[$tag] as $sub_issue) {
                                if (in_array($sub_issue, $TrueSet[$package]['issues'])) {
                                    array_push($TP_NS_TrueSetResults[$file], $tag);
                                    continue 2;
                                }
                            }
                        }
                    }
                }
            } elseif ($tag === '??uname-a??') {
                echo 'https://codesearch.debian.net/search?q=package%3A', $package, '+uname', "\n";
            }
            
            
            if ((isset($blacklist_comments[$tag]) && in_array($package, $blacklist_comments[$tag]))
                || (isset($KnownFPs[$tag])        && in_array($package, $KnownFPs[$tag]))
                || (isset($IssuesSkip[$tag])      && in_array($package, $IssuesSkip[$tag]))) {
                continue;
            }
                
            if ($UniqueNoteResults && isset($comments_lookup_name[$tag]) && isset($TrueSet[$package]['comments'])) {
                foreach ($comments_lookup_name[$tag] as $word_search) {
                    if (strpos($TrueSet[$package]['comments'], $word_search) !== false) {
                        echo '@Skip by comment@ , tag: ' , $tag , ' comment: ' , $TrueSet[$package]['comments'] , "\n";
                        continue;
                    }
                }
            }
            
			/*
            if ($strip_more_then_instance_issues && $NotesResultCount > 1
                && in_array($tag, array('build_id_variation_requiring_further_investigation',
                                        'random_id_in_pdf_generated_by_dblatex','too_much_input_for_diff'))) {
                continue ;
            }
			*/
            
            if (!isset($ResultAllNotes[$tag])) {
                $ResultAllNotes[$tag] = array();
            }
            if (!in_array($package, $ResultAllNotes[$tag])) {
				$ResultAllNotes[$tag][$package] = array();
				$ResultAllNotes[$tag][$package]['arch']  = $package_arr['arch'];
				$ResultAllNotes[$tag][$package]['count'] = $package_arr['count'];
				$ResultAllNotes[$tag][$package]['addlinescount'] = $DiffoscopeAnalyzer->add_array_count;
				
            }
        }
        
        if (empty($TP_TrueSetResults[$file])) {
            unset($TP_TrueSetResults[$file]);
        }
        if (empty($TP_NS_TrueSetResults[$file])) {
            unset($TP_NS_TrueSetResults[$file]);
        }
        
        
        if ($tag_found === false) {
            $finds_without_tag[$file] = array_keys($DiffoscopeAnalyzer->NotesResult);
        }

        if ($has_trueset === true && isset($TrueSet[$package])) {
            if (isset($TrueSet[$package]['issues'])) {
				
                $FN_TrueSetResults[$file] = array();
                foreach ($TrueSet[$package]['issues'] as $TStag) {
                    if (in_array($TStag, $TSBlackList)) {
                        continue;
                    }
                        
                    if (!isset($DiffoscopeAnalyzer->NotesResult[$TStag])) {
                        $FN_TrueSetResults[$file][]=$TStag;
                    }
                }

                if (empty($FN_TrueSetResults[$file])) {
                    unset($FN_TrueSetResults[$file]);
                }
            }
        }
    }
    
    /*
    print_r($DiffoscopeAnalyzer->result);
    print_r($DiffoscopeAnalyzer->NotesResult);
    */
    $endtime = (microtime(true) - $DiffoscopeAnalyzer->timestart);
    $endcputime = getrusage();
    $utime = rutime($endcputime, $DiffoscopeAnalyzer->cputimestart, 'utime');
    $stime = rutime($endcputime, $DiffoscopeAnalyzer->cputimestart, 'stime');
    if ($DiffoscopeAnalyzer->add_array_count != $DiffoscopeAnalyzer->rem_array_count) {
        $diff_lines = max($DiffoscopeAnalyzer->add_array_count, $DiffoscopeAnalyzer->rem_array_count)
                        - min($DiffoscopeAnalyzer->add_array_count, $DiffoscopeAnalyzer->rem_array_count);
        echo 'Added/Removed lines are different by: ' , $diff_lines , "\n";
        echo 'Total Lines: add = ';
        echo $DiffoscopeAnalyzer->add_array_count;
        echo ' min = ' , $DiffoscopeAnalyzer->rem_array_count , "\n";
    } else {
        echo 'Total Lines: same  = ' , $DiffoscopeAnalyzer->add_array_count , "\n";
    }
    
    echo 'FileSize: ' , $file_size , ' Lines: ' , $DiffoscopeAnalyzer->linecount;
    echo ' Execution Time: ' , format_sec($endtime) , ' # This process used ' , $utime , ' ms for its computations | ';
    echo 'It spent '          , $stime , ' ms in system calls';
    echo ($endtime > $max_time_warn ? ' TEST-PERFORMACE-IMPROVMENT':'') , "\n";
    echo '------------------------' , "\n";
    $file_i++;
}

echo "\n", 'Results of #', $file_i, ' packages | #' , ($files_count-$file_i), ' packages was skipped', "\n";
function escape_shell_arg(&$item, $key)
{
    $item = escapeshellarg($item);
}


    ksort($ResultAllNotes);
$git_commit = '';

	//echo '---ResultAllNotes--',"\n";
	//print_r( $ResultAllNotes );
	

foreach ($ResultAllNotes as $result_note_tag => $result_note_packages_arr) {
	//echo '---result_note_packages_arr--',"\n";
	//print_r( $result_note_packages_arr );

	// sort result_note_packages_arr by count catches
	uasort($result_note_packages_arr, function($a, $b) {
		return $a['count'] - $b['count'] ?: $a['addlinescount'] - $b['addlinescount'];
	});
	
	$result_note_packages = array_keys($result_note_packages_arr);
	
    if (substr($result_note_tag, 0, 1) !== '?') {
        $result_note_packages_escaped = $result_note_packages;
        echo '../misc/edit-notes ';
        array_walk($result_note_packages_escaped, 'escape_shell_arg');
        echo implode(' ', $result_note_packages_escaped);
        echo ' -a ' , $result_note_tag , "\n";
        $pacakges_num_in_tag = count($result_note_packages);
        $git_commit .= 'git commit -a -m "Tag ';
        if ($pacakges_num_in_tag > 1) {
            $git_commit .= $pacakges_num_in_tag . ' total packages';
        } elseif ($pacakges_num_in_tag === 1) {
            $git_commit .= substr(implode(' ', $result_note_packages_escaped), 1, -1);
        }
        $git_commit .= ' with ' . $result_note_tag .'"' . ($git_push_show ? ' && git push': '') . "\n";
    } else {
        echo 'IssueGlobalRule: ' , $result_note_tag , ' ' , implode(' ', $result_note_packages) , "\n";
    }
    
    if ($show_packages_notes_urls) {
        foreach ($result_note_packages_arr as $package_name_ws => $package_data) {
			$package_target_arch = $package_data['arch'];
			$package_catched_by_issuetype = $package_data['count'];
			
			if($package_catched_by_issuetype > 1) {
				echo 'catched: ' , $package_catched_by_issuetype, ' addcount: ' , $package_data['addlinescount'] ,"\n";
			}
            printf($RemotePackageHTMLDiff,
				$DiffoscopeAnalyzer->EnvByPackageAndArch($package_name_ws, $package_target_arch),
				$package_target_arch,
				$package_name_ws);
            echo "\n";
        }
    }
}


	/*
	TODO: ADD REPORTING = CORRECT ENV + Detect if Diffoscope version > 60 (IN HTML Report) = it related to 'too_much_input_for_diff' only?
		// add issue diffoscope_runs_forever:
		
		if(is_array($this->NotesResult) && isset($this->diffoscope_forever_arr[$this->package_name])) { //todo1 - INCORRECT env REPORTED...
			$this->addRule('diffoscope_runs_forever', 'diffoscope_runs_forever', '#from_breakages#' , '#from_breakages#');
			// $this->current_rules_count--; 
							$this->diffoscope_forever_arr[$packageName][$relatedEnv][]=$relatedArch;

		}
	*/

echo $git_commit;
if (!empty($finds_without_tag)) {
    echo 'Results without tag results:', "\n";
    echo '#' , count($finds_without_tag) , ' packages' , "\n";
    print_r($finds_without_tag);
}

if (!empty($FN_Results)) {
    echo 'FNs - no results found - need manual further investigation!' , "\n";
    echo '#' , count($FN_Results) , ' packages' , "\n";
    var_export($FN_Results);
    echo "\n" , '-----------------------' , "\n\n";
}

// FN via TrueSet
if (!empty($FN_TrueSetResults)) {
    echo 'FNs - missing results that exists in TrueSets By Package:', "\n";
    echo '#' , count($FN_TrueSetResults) , ' packages' , "\n";
    var_export($FN_TrueSetResults);
    echo "\n" , '-----------------------' , "\n\n";
    echo 'FNs - missing results that exists in TrueSets By Issue Count:', "\n";
    $FN_TS_values = array();
    foreach ($FN_TrueSetResults as $FNitem) {
        foreach ($FNitem as $fn_issue_tag) {
            array_push($FN_TS_values, $fn_issue_tag);
        }
    }
    $COUNT_FN_TS = array_count_values($FN_TS_values);
    asort($COUNT_FN_TS);
    print_r($COUNT_FN_TS);
    echo "\n" , '-----------------------' , "\n\n";
    echo 'FNs - missing results that exists in TrueSets By Issue Type:', "\n";
	$FN_All_Values = array();
    foreach ($FN_TrueSetResults as $FNFile => $FNitem) {
        foreach ($FNitem as $fn_issue_tag) {
            if (!isset($FN_All_Values[$fn_issue_tag])) {
                $FN_All_Values[$fn_issue_tag] = array();
            }
			
			if (!in_array($FNitem, $FN_All_Values[$fn_issue_tag])) {
                array_push($FN_All_Values[$fn_issue_tag], $FNFile);
            }
			
        }
    }
	
	foreach ($FN_All_Values as $FNTag => $FNFiles) {
		
			echo 'issue: ' ,  $FNTag , "\n";
				foreach ($FNFiles as $FNFile) {		
					printf($RemotePackageHTMLDiff, 
						$DiffoscopeAnalyzer->EnvByPackageAndArch($DiffoscopeAnalyzer->FileResults[$FNFile]['package_name'], $DiffoscopeAnalyzer->FileResults[$FNFile]['arch']),
						$DiffoscopeAnalyzer->FileResults[$FNFile]['arch'],
						$DiffoscopeAnalyzer->FileResults[$FNFile]['package_name']);
					echo "\n";
				}
    }
		
}



// TP but not subset issue reported - via TrueSet

if (!empty($TP_NS_TrueSetResults)) {
    echo 'TPs[NS] - found results that exists in TrueSets as subset issue:', "\n";
    echo '#' , count($TP_NS_TrueSetResults) , ' packages' , "\n";
    print_r($TP_NS_TrueSetResults);
}

// TP via TrueSet
if (!empty($TP_TrueSetResults)) {
    echo 'TPs - found results that exists in TrueSets:', "\n";
    echo '#' , count($TP_TrueSetResults) , ' packages' , "\n";
    if ($show_tps) {
        print_r($TP_TrueSetResults);
    }
}

if(!empty($GlobalRawResults)) {
	$sorted_raw_result = array();
	echo 'GlobalRawResults:', "\n";
	file_put_contents('GlobalRawResults.txt', '$global_raw_results='.var_export($GlobalRawResults,1).';', FILE_APPEND | LOCK_EX);
	foreach($GlobalRawResults as $gw) {
		foreach($gw as $gwr) {
			if(!isset($sorted_raw_result[$gwr['type']]))
				$sorted_raw_result[$gwr['type']] = array();
			
			if(!in_array($gwr['linestring'] , $sorted_raw_result[$gwr['type']]))
				$sorted_raw_result[$gwr['type']][]=$gwr['linestring'];
		}
	}
	foreach($sorted_raw_result as $k => $v) {
		sort($sorted_raw_result[$k]);
	}
	print_r($sorted_raw_result);
}



$script_ru_end = getrusage();
echo "\n" , 'Total execution time: ' , format_sec(microtime(true) - $script_start_time);
echo ' This process used ' , rutime($script_ru_end, $script_ru_start, 'utime') , ' ms for its computations | ';
echo 'It spent '           , rutime($script_ru_end, $script_ru_start, 'stime') , ' ms in system calls' , "\n";