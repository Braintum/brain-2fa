const path = require('path');
const DependencyExtractionWebpackPlugin = require('@wordpress/dependency-extraction-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const { CleanWebpackPlugin } = require('clean-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');

module.exports = (env, argv) => {
	const isProduction = argv.mode === 'production';

	return {
		entry: {
			'login': './src/js/login.js',
			'admin': ['./src/js/admin.js', './src/scss/admin.scss'],
		},
		output: {
			path: path.resolve(__dirname, 'assets'),
			filename: 'js/[name].js',
		},
		module: {
			rules: [
				{
					test: /\.js$/,
					exclude: /node_modules/,
					use: {
						loader: 'babel-loader',
						options: {
							presets: [
								[
									'@babel/preset-env',
									{
										targets: {
											browsers: ['last 2 versions', '> 1%', 'not dead'],
										},
										modules: false,
									},
								],
							],
						},
					},
				},
				{
					test: /\.s?css$/,
					use: [
						MiniCssExtractPlugin.loader,
						'css-loader',
						{
							loader: 'sass-loader',
							options: {
								api: 'modern',
								sassOptions: {
									outputStyle: isProduction ? 'compressed' : 'expanded',
								},
							},
						},
					],
				},
			],
		},
		plugins: [
			new CleanWebpackPlugin({
				cleanOnceBeforeBuildPatterns: ['js/*.js', 'js/*.asset.php', 'css/*.css'],
				cleanAfterEveryBuildPatterns: ['!css/**', '!js/**'],
			}),
			new DependencyExtractionWebpackPlugin({
				injectPolyfill: true,
				combineAssets: true,
			}),
			new MiniCssExtractPlugin({
				filename: 'css/[name].css',
			}),
		],
		optimization: {
			minimize: isProduction,
			minimizer: [
				new TerserPlugin({
					terserOptions: {
						format: {
							comments: false,
						},
					},
					extractComments: false,
				}),
			],
		},
		resolve: {
			extensions: ['.js', '.json'],
			alias: {
				'@': path.resolve(__dirname, 'src/js'),
			},
		},
		devtool: isProduction ? false : 'source-map',
		stats: {
			colors: true,
			hash: false,
			version: false,
			timings: true,
			assets: true,
			chunks: false,
			modules: false,
			reasons: false,
			children: false,
			source: false,
			errors: true,
			errorDetails: true,
			warnings: true,
			publicPath: false,
		},
	};
};
